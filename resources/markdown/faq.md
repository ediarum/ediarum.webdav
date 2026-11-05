
# Frequently Asked Questions for Users 

### Meine Datei kann nicht gespeichert werden. Was nun?

Wenn beim Speichern eine Fehlermeldung passiert, dann ist das in der Regel ein Problem mit der Sperre auf der Datei. Sie sperren immer eine Datei, indem Sie sie öffnen. Die Sperre verhindert, dass mehrere Nutzer eine Datei gleichzeitig bearbeiten. Beim Schließen der Datei wird die Sperre wieder aufgehoben. Wenn jedoch Oxygen nicht richtig geschlossen wird (z. B. wenn Ihr Computer abstürzt, Sie das Beenden von Oxygen erzwingen, usw.), kann der Server die bei der erneuten Öffnung von Oxygen neu entstandene Sperre nicht erkennen. Die Lösung besteht darin die Datei zu entsperren:
- Navigieren Sie in der «Datenquellen Explorer» (Englisch: "Data Source Explorer") zu der problematischen Datei
- Beim Rechtsklick auf die Datei erscheint ein Dropdownmenü. Drucken Sie auf "Unlock/Entsperren": ![Entsperren Screenshot](images/entsperren_screenshot.png)
- Jetzt sollten Sie wieder speichern können.
- Nachdem Sie die jetzt die Datei gespeichert haben, ist es empfehlenswert, falls Sie weiter an der Datei arbeiten möchten, die Datei wieder zu sperren. Das machen Sie, in dem Sie die Datei schließen (natürlich, nachdem Sie die Datei gespeichert haben, damit Ihre Änderungen nicht verloren gehen) und wieder aufmachen.

# Frequently Asked Questions for Developers

### Can I add data transformations or other commits I made locally?

Yes, in the admin interface, as long as the project has a Git remote. Commits are only ever added
as a fast-forward of the project's tracked branch (shown as "Tracks branch" on the project page),
so nothing on the server is overwritten.

1. **Make your changes on a branch.** Branch off the tracked branch, commit your changes and push
   the branch to the project's Git remote.
2. **Turn on maintenance mode.** On the project page, enable maintenance mode in the settings
   panel. All WebDAV locks must be released first, i.e. nobody may have a file open. While
   maintenance mode is on, editors can't save, so no new commits reach the tracked branch.
3. **Bring your branch up to date.** Editors' saves are committed to the tracked branch and pushed
   to the remote all the time, so it has probably moved on since you branched off. Fetch, rebase
   your branch onto the tracked branch, and push it again (`git push --force-with-lease`).
4. **Add the commits.** Under "Add Commits From Another Branch", choose your branch. The page shows
   how many commits will be added and which files change. If it says the branch has diverged,
   repeat step 3. Then click "Add commits to …". The tracked branch is fast-forwarded to your
   branch, the changed files in the `data` folder are synchronized to eXist-db and the ediarum
   services (if configured), and the result is pushed to the Git remote.
5. **Turn maintenance mode off** again, so editors can continue working.
