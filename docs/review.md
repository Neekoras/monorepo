# Automated code review

Every pull request gets an AI review from [OpenCodeReview](https://github.com/alibaba/open-code-review), run by the `Review` workflow (`.github/workflows/review.yml`). It posts one summary comment per pull request, updated in place, plus inline comments on the changed lines. A later push reviews only the new commits, and an inline comment is never repeated on a line range that already has one. When a push removes the lines an inline comment was attached to, the reviewer resolves its own thread; threads a human has replied to are left alone. Findings are advisory: treat them like any other review comment.

The review runs when a pull request is opened, marked ready for review, reopened, or pushed to; draft pull requests are skipped. A maintainer (owner, member, or collaborator of the repository) can re-run it at any time by commenting `/open-code-review` on the pull request, or `/open-code-review full` to review the whole pull request again from its merge base. The `Review` workflow can also be run from the Actions tab with the pull request number and an optional full-review switch; a manual run uses the workflow file on `main`.

The workflow uses the `pull_request_target` event so the LLM credentials are available to pull requests from forks. This is safe because the action checks out the trusted base branch and reviews the base-to-head diff from git objects; it never runs code from the pull request.

## CI setup

In the monorepo's **Settings → Secrets and variables → Actions**, set:

- secret `OCR_LLM_URL` — the LLM API endpoint. A base URL is enough: the reviewer appends `/v1/messages` (Anthropic) or `/chat/completions` (OpenAI-compatible) when the path is missing, so `https://api.anthropic.com` or `https://api.openai.com/v1` both work.
- secret `OCR_LLM_AUTH_TOKEN` — the API key for that endpoint.
- variable `OCR_LLM_MODEL` — the model name, for example `claude-sonnet-5`. There is no default.
- variable `OCR_LLM_USE_ANTHROPIC` — `true` for the Anthropic Messages protocol, `false` for an OpenAI-compatible endpoint. An unset variable selects Anthropic.
- variable `OCR_LLM_PROTOCOL` — optional. `openai-responses` for models that answer only through the OpenAI Responses API, such as GPT-5.6 and GPT-6; `openai` or `anthropic` otherwise. When set, it overrides `OCR_LLM_USE_ANTHROPIC`.

`GITHUB_TOKEN` is provided by GitHub Actions. The workflow grants it `pull-requests: write` to post the review, and `contents: write` because GitHub gates resolving review threads on repository write access; the job itself never writes to the repository. Comments appear as `github-actions[bot]`.

## Review rules

Repository-specific guidance for the reviewer lives in `.opencodereview/rule.json`. The first entry whose glob matches a changed file decides the prompt for that file, and `merge_system_rule` appends the entry to the built-in rule for the file's language instead of replacing it. Files that match no entry fall back to the built-in rules. See the [review rules reference](https://open-codereview.ai/docs/review-rules) for the schema and glob syntax.

## Upgrading

The workflow pins the action to a commit (`ratchet` enforces this in CI) and the CLI to the matching npm release through the `ocr_version` input. Bump both to the same release tag.
