#!/usr/bin/env bash
#
# Publishes the current state of `main` to the public GitHub repository as one snapshot commit.
#
#   build-tools/publish.sh <version>          e.g. build-tools/publish.sh 1.1.0
#
# The public history is a chain of release snapshots (v1.0.0 → v1.1.0 → …), not the internal
# history: each snapshot is a new commit on top of the public `main` whose files are exactly
# `main`'s, minus the paths in EXCLUDE. Nothing is merged back; local `main` never contains
# the public commits, and the next publish again starts from `main`'s tree.
#
# On the first publish (the public repo is still empty) the snapshot is a root commit instead.
#
# Before pushing, the script checks the snapshot for internal hosts and secret-looking strings
# (see FORBIDDEN / ALLOWED), shows what changes compared to the public repo, and asks for
# confirmation. It pushes the snapshot to the public `main` and an annotated tag v<version>.
# Creating the GitHub release from that tag (and with it the Zenodo version) is done by hand.

set -euo pipefail

REMOTE=github
SOURCE_BRANCH=main
PUBLIC_BRANCH=main

# Paths left out of the public snapshot.
EXCLUDE=(
    .gitlab-ci.yml
    planning
)

# Extended regexes that must not appear anywhere in the snapshot.
FORBIDDEN=(
    'bbaw\.de'
    'php-prod'
    'gitup\.uni-potsdam\.de'
    'telota-intermediate-proxy'
    '/home/[a-z]'
    '/opt/projects'
    'glpat-[A-Za-z0-9_-]{20,}'
    'gh[pousr]_[A-Za-z0-9]{20,}'
    'APP_KEY=base64:[A-Za-z0-9+/=]{20,}'
    '-----BEGIN [A-Z ]*PRIVATE KEY-----'
)

# Hits that are fine, as `file:regex` matched against the reported line.
ALLOWED=(
    'README.md:www\.bbaw\.de/telota|telota@bbaw\.de|ediarum@bbaw\.de'
    'build-tools/publish.sh:.*'
)

die() { echo "error: $*" >&2; exit 1; }

version=${1:-}
[[ $version =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "usage: $0 <version>, e.g. $0 1.1.0"
tag="v$version"

cd "$(git rev-parse --show-toplevel)"

[[ -z $(git status --porcelain) ]] || die "the working tree has uncommitted changes; commit them first"
git rev-parse --verify -q "$SOURCE_BRANCH" >/dev/null || die "no local branch '$SOURCE_BRANCH'"
git remote get-url "$REMOTE" >/dev/null 2>&1 || die "no remote '$REMOTE'"

echo "Fetching $REMOTE …"
# --prune: a deleted public branch must not survive as a stale remote-tracking ref. --no-tags: the
# public tags aren't needed locally (they're checked with ls-remote), and pruning or clobbering
# fetched tags could touch the internal ones.
git fetch -q --prune --no-tags "$REMOTE"
public="refs/remotes/$REMOTE/$PUBLIC_BRANCH"
# An empty public repo (first publish) has no branch yet: the snapshot then becomes a root commit.
first=0
if ! git rev-parse --verify -q "$public" >/dev/null; then
    if git ls-remote --exit-code "$REMOTE" >/dev/null 2>&1; then
        die "$REMOTE has refs but no branch '$PUBLIC_BRANCH'"
    fi
    first=1
fi
if git ls-remote --exit-code --tags "$REMOTE" "refs/tags/$tag" >/dev/null 2>&1; then
    die "$REMOTE already has the tag $tag"
fi
if git rev-parse --verify -q "refs/tags/$tag" >/dev/null; then
    die "there is already a local tag $tag (on $(git rev-parse --short "$tag^{commit}")); the published tag is created locally first"
fi

# --- build the snapshot tree in a throwaway index, without touching the working tree
index=$(mktemp)
trap 'rm -f "$index"' EXIT
export GIT_INDEX_FILE=$index
git read-tree "$SOURCE_BRANCH"
for path in "${EXCLUDE[@]}"; do
    git rm -r -q --cached --ignore-unmatch -- "$path"
done
tree=$(git write-tree)
unset GIT_INDEX_FILE

# --- check it for anything internal
problems=0
for pattern in "${FORBIDDEN[@]}"; do
    while IFS= read -r hit; do
        [[ -z $hit ]] && continue
        line=${hit#"$tree":}             # file:lineno:text
        file=${line%%:*}
        text=${line#*:*:}
        allowed=0
        for rule in "${ALLOWED[@]}"; do
            if [[ $file == "${rule%%:*}" ]] && grep -qE -- "${rule#*:}" <<<"$text"; then
                allowed=1
                break
            fi
        done
        if ((allowed == 0)); then
            echo "  forbidden ($pattern): $line"
            problems=$((problems + 1))
        fi
    done < <(git grep -I -n -E -e "$pattern" "$tree" || true)
done
((problems == 0)) || die "$problems forbidden match(es) in the snapshot; fix them on $SOURCE_BRANCH first"

if ((first == 0)) && [[ $(git diff --name-only "$public" "$tree" | wc -l) -eq 0 ]]; then
    die "the snapshot is identical to $REMOTE/$PUBLIC_BRANCH; nothing to publish"
fi

citation_version=$(git show "$tree:CITATION.cff" 2>/dev/null | sed -n 's/^version: *//p' | tr -d '"' || true)
if [[ -n $citation_version && $citation_version != "$version" ]]; then
    echo "warning: CITATION.cff says version $citation_version, not $version"
fi

# --- show and confirm
echo
if ((first)); then
    echo "Snapshot of $SOURCE_BRANCH ($(git rev-parse --short "$SOURCE_BRANCH")) as $tag: FIRST publish, $REMOTE is empty."
    echo "Left out: ${EXCLUDE[*]}"
    echo
    echo "$(git ls-tree -r --name-only "$tree" | wc -l) files, by top-level path:"
    git ls-tree -r --name-only "$tree" | cut -d/ -f1 | sort | uniq -c
else
    echo "Snapshot of $SOURCE_BRANCH ($(git rev-parse --short "$SOURCE_BRANCH")) as $tag, on top of $REMOTE/$PUBLIC_BRANCH ($(git rev-parse --short "$public"))."
    echo "Left out: ${EXCLUDE[*]}"
    echo
    git diff --stat=100 "$public" "$tree" | tail -n 25
fi
echo
read -r -p "Push to $REMOTE/$PUBLIC_BRANCH and tag $tag? [y/N] " answer
[[ $answer == [yY] ]] || { echo "Nothing pushed."; exit 0; }

if ((first)); then
    snapshot=$(git commit-tree "$tree" -m "Release $version")
else
    snapshot=$(git commit-tree "$tree" -p "$public" -m "Release $version")
fi
git tag -a "$tag" "$snapshot" -m "Release $version"
if ! git push "$REMOTE" "$snapshot:refs/heads/$PUBLIC_BRANCH" "refs/tags/$tag"; then
    git tag -d "$tag" >/dev/null
    die "push failed; nothing was published, and the local tag $tag was removed again"
fi

echo
echo "Published $tag ($(git rev-parse --short "$snapshot")). Create the GitHub release from the tag by hand."
