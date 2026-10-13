#!/usr/bin/env bash
# usage: changelog-section.sh <version> [changelog]
# Prints the CHANGELOG.md section of a version (without its heading), for the GitHub release notes.
# Fails when the version has no section, so that a release is never published with empty notes.
set -euo pipefail

version=${1:?usage: changelog-section.sh <version> [changelog]}
changelog=${2:-CHANGELOG.md}

section=$(awk -v heading="## [${version}]" '
    index($0, heading) == 1 { found = 1; next }
    found && /^## \[/ { exit }
    found { print }
' "$changelog" | sed -e '/^---$/d' | sed -e '/./,$!d' | sed -e ':a' -e '/^\n*$/{$d;N;ba' -e '}')

if [ -z "$section" ]; then
    echo "No section for version ${version} in ${changelog}" >&2
    exit 1
fi

printf '%s\n' "$section"
