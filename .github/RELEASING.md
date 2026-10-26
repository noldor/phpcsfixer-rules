# Development and release workflow

1. Fetch `origin/master`, switch to `master`, fast-forward it from the remote,
   then create a new task branch before changing files. Implement a coherent task and run
   `composer check`, release-script tests and distribution verification.
2. Complete independent review, push the task branch and open a PR into `master`.
3. Use Conventional Commits for branch commits and the PR title, then use an ordinary merge commit. Required GitHub
   Actions checks are `Quality` and `PR title`; all review threads must be resolved.
   Keep the branch up to date with `master`. A request for a new shared check
   authorizes enabling auto-merge using an ordinary merge commit, subject to
   these checks and resolved review discussions. Other tasks require the user's
   explicit merge authorization.
4. After merge, Actions rechecks the resulting `master` commit. Only a successful
   quality job can start the release job, which creates an immutable tag and
   requests a Packagist refresh. Publication succeeds only when Packagist exposes
   the expected version at the exact checked commit.

## Versions

The first release is always `v0.0.1`. For subsequent releases, the commits
introduced since the previous tag select the SemVer increment. Ordinary merges
retain the branch commits for analysis; the highest applicable increment wins:

| Change | Example | Increment |
| --- | --- | --- |
| New rule or compatible feature | `feat(rules): add a rule` | Minor |
| Incompatible behaviour or configuration | `feat(rules)!: change allowed paths` | Major |
| Breaking-change footer | `BREAKING CHANGE: describe the incompatibility` | Major |
| Fixes and other merges | `fix(rules): handle callables` | Patch |

Versions are derived from `vX.Y.Z` tags. Keep `composer.json` without a `version`
field. Do not create or move release tags manually. The first-version override
applies only while there are no existing release tags. The release infrastructure
is excluded from distribution archives.

## Repository activation

Follow the repository's publication authorization, including the shared-check
workflow, for initial publication as well as later changes.

For an empty repository, establish an empty initial commit on `master`, then
put the package and its automation in a task branch and PR.
This creates the base needed for branch-based development without placing the
implementation directly on `master`. Never bypass existing protection rules to
seed a repository that already has history.

Packagist can register the default branch after the first merge puts
`composer.json` on `master`. Leave the Packagist credentials unset during
bootstrap: the release preflight stops before tagging. After the first merge,
register the package and configure its credentials, then dispatch the workflow
on `master` to create `v0.0.1`. Later merges use the normal automatic release
path.

Configure the merge settings before merging the initial PR, and complete
Packagist activation after its manifest reaches `master`:

- Default branch: `master`.
- Merge method: ordinary merge commits, preserving branch history.
- Active branch ruleset: import [rulesets/master.json](rulesets/master.json).
  It requires PRs, passing `Quality` and `PR title` checks from GitHub Actions,
  an up-to-date branch and resolved discussions; it blocks force
  pushes and deletion and has no bypass actors.
- Register `https://github.com/noldor/phpcsfixer-rules` on Packagist as
  `noldors/phpcsfixer-rules` after the repository contains its manifest.
- Repository Actions variable `PACKAGIST_USERNAME`: the publishing account's
  Packagist login; it need not equal the package vendor `noldors`.
- Repository Actions secret `PACKAGIST_TOKEN`: that account's **safe API token**.
  Existing-package refreshes require only the safe token. Register the package
  through Packagist's UI; do not place the account's main token in this workflow.
- Actions must be enabled. The release job requests `contents: write` for the
  repository's built-in `GITHUB_TOKEN`; a separate GitHub PAT is unnecessary.

Use the repository's Rulesets UI to import the JSON, or create the ruleset through
the authenticated GitHub CLI after checking existing rulesets to avoid duplicates:

```sh
gh api repos/noldor/phpcsfixer-rules/rulesets --input .github/rulesets/master.json
```

The workflow updates Packagist in the same run that creates the tag. It does not
depend on a second workflow being triggered by a `GITHUB_TOKEN`-created tag.
Packagist indexes Git tags; this workflow does not upload a ZIP to Packagist.

## Verification and recovery

```sh
composer validate --strict
composer check
python3 -m unittest discover -s .github/tests
composer archive --format=zip --dir=build/distribution --file=composer-package
git archive --format=zip --output=build/distribution/git-package.zip HEAD
python3 .github/scripts/verify_archive.py build/distribution/composer-package.zip build/distribution/git-package.zip
```

The Git archive uses committed files. Before committing new package files,
validate the same content with a disposable Git tree outside this checkout.

Missing Packagist credentials or registration fail before a tag is created. If
the update fails after tagging, rerun the release job or dispatch the workflow
on `master`. A tag already pointing at that commit is reused; it is not bumped
again, overwritten or deleted. Retry the latest `master` run if the branch has
advanced. The workflow serializes releases, and obsolete checked-out commits
cannot tag a newer `master` commit.

Treat a Packagist version pointing at another commit as an error to investigate;
keep existing tags intact. Fix the publishing credentials or registry problem
and retry rather than changing an already published version.

See [GitHub tag action](https://github.com/mathieudutour/github-tag-action) and
[Packagist API documentation](https://packagist.org/apidoc#update-a-package).
