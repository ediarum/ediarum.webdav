/** Response shapes of /api/webdav/… (see the controllers that build them). */

export interface ProjectRef {
    id: number;
    name: string;
}

export interface ProjectSummary {
    id: number;
    name: string;
    slug: string;
    initialized: boolean;
    isInMaintenanceMode: boolean;
    /** ISO 8601; the latest WebDAV commit, null if the project was never edited. List only. */
    lastEditedAt?: string | null;
}

export interface Lock {
    id: number;
    owner: string;
    file: string;
    created: string;
    timeElapsed: string;
}

export interface LockedPath {
    id: number;
    path: string;
    lockedBy: string;
    createdAt: string;
}

export interface FailedPush {
    uuid: string;
    file: string;
    time: string;
}

export interface UserRef {
    id: number;
    name: string;
}

export interface ProjectSettings {
    name: string;
    slug: string;
    git_remote_url: string | null;
    git_username: string | null;
    git_branch: string | null;
    push_to_ediarum_services: boolean;
    is_index_app_source_of_truth: boolean;
    exist_base_url: string | null;
    exist_data_path: string | null;
    exist_username: string | null;
    has_git_access_token: boolean;
    has_exist_password: boolean;
}

export interface ProjectDetail extends ProjectSummary {
    webdavUrl: string;
    gitBranch: string | null;
    gitCommitBroken: boolean;
    gitCommitBrokenReason: string | null;
    gitRemoteConfigured: boolean;
    gitRemoteUrl: string | null;
    ediarumServicesUrl: string | null;
    existConfigured: boolean;
    existUrl: string | null;
    settings: ProjectSettings;
    folders: string[];
    locks: Lock[];
    lockedPaths: LockedPath[];
    failedJobs: FailedPush[];
    users: UserRef[];
    availableUsers: UserRef[];
}

export interface User {
    id: number;
    name: string;
    email: string;
    isAdmin: boolean;
}

export interface AdminData {
    commitHours: number;
    recentCommits: {
        project: ProjectRef;
        committedAt: string;
        committedAgo: string;
        commitHash: string | null;
        commitUrl: string | null;
    }[];
    failedGitRemotePushes: { project: ProjectRef; failedAt: string; reason: string }[];
    projectsUsingMasterFallback: { project: ProjectRef; since: string }[];
    projectsWithBrokenCommits: { project: ProjectRef; since: string; reason: string }[];
}

export interface AppConfig {
    adminPanelEnabled: boolean;
}
