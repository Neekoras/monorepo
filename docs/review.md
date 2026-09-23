# Automated code review

Every pull request gets an AI review from [OpenCodeReview](https://github.com/alibaba/open-code-review), run by the `Review` workflow (`.github/workflows/review.yml`). It posts one summary comment per pull request, updated in place, plus inline comments on the changed lines. A later push reviews only the new commits, and an inline comment is never repeated on a line range that already has one. Findings are advisory: treat them like any other review comment.

The review runs when a pull request is opened, marked ready for review, reopened, or pushed to; draft pull requests are skipped. A maintainer (owner, member, or collaborator of the repository) can re-run it at any time by commenting `/open-code-review` on the pull request, or by running the `Review` workflow from the Actions tab with the pull request number. The manual run also works from a branch, which is how to test a change to the workflow before it lands on `main`.

The workflow uses the `pull_request_target` event so the LLM credentials are available to pull requests from forks. This is safe because the action checks out the trusted base branch and reviews the base-to-head diff from git objects; it never runs code from the pull request.

## CI setup

In the monorepo's **Settings → Secrets and variables → Actions**, set:

- secret `OCR_LLM_URL` — the LLM API endpoint. A base URL is enough: the reviewer appends `/v1/messages` (Anthropic) or `/chat/completions` (OpenAI-compatible) when the path is missing, so `https://api.anthropic.com` or `https://api.openai.com/v1` both work.
- secret `OCR_LLM_AUTH_TOKEN` — the API key for that endpoint.
- variable `OCR_LLM_MODEL` — the model name, for example `claude-sonnet-5`. There is no default.
- variable `OCR_LLM_USE_ANTHROPIC` — `true` for the Anthropic Messages protocol, `false` for an OpenAI-compatible endpoint. An unset variable selects Anthropic.
- variable `OCR_LLM_PROTOCOL` — optional. `openai-responses` for models that answer only through the OpenAI Responses API, such as GPT-5.6 and GPT-6; `openai` or `anthropic` otherwise. When set, it overrides `OCR_LLM_USE_ANTHROPIC`.

`GITHUB_TOKEN` is provided by GitHub Actions; the workflow grants it `pull-requests: write` to post the review, which appears as `github-actions[bot]`.

### Review identity

To post under a dedicated name and avatar instead of `github-actions[bot]`, give the workflow a GitHub App to act as. It mints a short-lived token from the app on every run, and falls back to the default token while no app is configured.

1. Create the app at **Settings → Developer settings → GitHub Apps → New GitHub App**, under the `utopia-php` organization if you can so it is not tied to a personal account. The app name becomes the reviewer's display name with `[bot]` appended, and its logo becomes the avatar. Disable the webhook. Under **Repository permissions** grant **Pull requests: Read and write**, **Contents: Read and write** (GitHub gates resolving review threads on it), and **Metadata: Read-only**; nothing else is needed.
2. Generate a private key on the app's settings page and note the **App ID** shown there.
3. Install the app on the monorepo.
4. In the monorepo's **Settings → Secrets and variables → Actions**, set:
   - variable `REVIEW_APP_ID` — the App ID
   - secret `REVIEW_APP_PRIVATE_KEY` — the contents of the downloaded `.pem` file

The first review under the new identity re-reviews each open pull request in full once, because a checkpoint is only trusted when the same identity wrote it.

## Review rules

Repository-specific guidance for the reviewer lives in `.opencodereview/rule.json`. The first entry whose glob matches a changed file decides the prompt for that file, and `merge_system_rule` appends the entry to the built-in rule for the file's language instead of replacing it. Files that match no entry fall back to the built-in rules. See the [review rules reference](https://open-codereview.ai/docs/review-rules) for the schema and glob syntax.

## Upgrading

The workflow pins the action to a commit (`ratchet` enforces this in CI) and the CLI to the matching npm release through the `ocr_version` input. Bump both to the same release tag.
