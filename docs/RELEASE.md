# Release process

Current stable target: **v1.2.0**.

## Creating a new version (e.g. v1.2.0)

1. **Ensure everything is ready**
   - [CHANGELOG.md](CHANGELOG.md) has the target version with date and full entry; `[Unreleased]` is at the top.
   - [UPGRADING.md](UPGRADING.md) has a section "Upgrading to X.Y.Z" with what's new and upgrade steps.
   - `make release-check` passes.

2. **Commit and push** any last changes to your default branch:
   ```bash
   git add -A
   git commit -m "chore(release): prepare 1.2.0"
   git push origin HEAD
   ```

3. **Create and push the tag**
   ```bash
   git tag -a v1.2.0 -m "Release v1.2.0 - REQ-UI-002 access_roles / AccessSubscriber"
   git push origin v1.2.0
   ```

4. **GitHub Actions** (if configured) may create the GitHub Release from the tag.

5. **Packagist** will pick up the new tag; users can then `composer require nowo-tech/uptime-monitor-bundle`.

After creating the release commit and tag, run `make check-no-cursor-coauthor` again **before** `git push` (REQ-GIT-001).
