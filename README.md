# ediarum.WEBDAV

[![DOI](https://zenodo.org/badge/DOI/10.5281/zenodo.17285966.svg)](https://doi.org/10.5281/zenodo.17285966)

Developed 2025 by Berlin-Brandenburg Academy of Sciences and Humanities

Part of ediarum <https://www.ediarum.org/index.html> (<ediarum@bbaw.de>)

Developed by TELOTA, a DH working group of the Berlin-Brandenburg Academy of Sciences and Humanities
<https://www.bbaw.de/telota> (<telota@bbaw.de>)

Ediarum core team:

* Nadine Arndt
* Martin Fechner
* Jan Wierzoch

Developer of ediarum.WEBDAV:

* Marcus Lampert

## Cite this Software

If you use this software, please cite it using the metadata in the file CITATION.CFF (for more information about CFF see https://citation-file-format.github.io/).

## What does it do?

This is an application allows users to edit files over a WevDAV connection and tracks those changes via automatic git commits (file versioning).
It can be used to store the files edited with ediarum.BASE.edit in a git repository, e.g. GitHub or GitLab.

ediarum.WEBDAV includes the following features:

* User Management (Create Users, Manage Passwords, Add to Projects)
* Project Management (Create Projects, Add git repositories to Projects)
* Configure a WevDAV connection to multiple different Projects
* When files are edited, the user can push those files to an exist-db
* When files are edited, the user can push those files to the ediarum services

## Documentation

* For development see [DEVELOPMENT.md](documentation/DEVELOPMENT.md).
* For deployment see [DEPLOYMENT.md](documentation/DEPLOYMENT.md).
* For usage see [USAGE.md](documentation/USAGE.md).

## License

ediarum.WEBDAV is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.

ediarum.WEBDAV is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License along with ediarum.DB If not, see <http://www.gnu.org/licenses/>.
