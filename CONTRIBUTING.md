# Contributing

[![Conventional Commits](https://img.shields.io/badge/commits-Conventional%20Commits-blue)](https://www.conventionalcommits.org/)
[![Conventional Branch](https://img.shields.io/badge/branches-Conventional%20Branch-blue)](https://conventionalbranch.org/#specification)

Thank you for your interest in contributing!

External contributors are welcome to propose changes through pull requests
from their own forks. Maintainers review contributions and decide what to
merge and release.

## Before you start

For a substantial change, consider opening an issue first to discuss the
approach. For a small bug fix or documentation improvement, you can open a
pull request directly.

Please check existing issues and pull requests to avoid duplicate work.
Do not include credentials, personal data, or other information that should
not be published.

## Reporting security vulnerabilities

Please do not report security vulnerabilities in public issues or pull
requests. Instead, use the **Report a vulnerability** option on this
repository's **Security** tab.

## Contributing through a fork

1. Fork this repository on GitHub.
2. Create a branch in your fork for your change.
3. Make your changes and run the relevant checks.
4. Push your branch to your fork.
5. Open a pull request to this repository.

Keep pull requests focused. Explain what changed, why it is needed, and how
you tested it. Link any related issue.

## Branch names

Follow the [Conventional Branch specification](https://conventionalbranch.org/#specification)
when naming branches in your fork. Use a short, descriptive name that reflects
the purpose of your change.

The `release/*` branches in this repository are maintained release lines,
not contribution branches.

## Commit messages

Follow the [Conventional Commits specification](https://www.conventionalcommits.org/):

```text
type(optional-scope): short description
```

For example:

```text
feat(js): add filtering by status
fix(module): handle an empty response
docs: clarify installation steps
```

Use a scope when it helps identify the affected part of the project. Mark
breaking changes according to the Conventional Commits specification.

Keep commits focused. If a pull request contains several work-in-progress
commits, a maintainer may ask you to tidy them before merging.

## Choosing a target branch

**Target `main` by default.** New features, documentation changes, and
general bug fixes should normally be proposed there.

Branches named `release/*` are maintained release lines. For example,
`release/v0.3` may receive fixes after the `v0.3` release. If a fix is
specifically needed for a supported release line, you may target the
relevant `release/*` branch. Explain in the pull request why that release
line needs the change.

Maintainers decide whether and how to backport changes between `main` and
release lines. A fix made on a release line may also need a separate change
on `main`.

Release tags such as `v0.3` and `v0.3.1` identify specific releases.
Maintainers create new tags for new releases; existing release tags are
not moved.

## Review and merging

A maintainer will review your pull request and may ask for changes or
additional tests. Opening a pull request does not guarantee that it will
be merged.

Only maintainers manage protected branches and publish releases. Please
leave release tagging and version-branch maintenance to them.

Thank you for contributing!