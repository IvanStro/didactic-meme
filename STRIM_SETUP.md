# StrIM development and deployment

## Current status — 15 October 2026

The StrIM source snapshot has been imported. See `README.md` for its structure,
dependency gaps, private configuration requirements, and the verified local-to-hosting
workflow through the provider's WebSSH terminal. The manually triggered Actions
connection check below is preserved; it has not been configured or run as a deployment.

## Original cloud setup plan

The intended workflow is: edit and validate the site in the Codex cloud environment, push the selected revision to GitHub, and run a GitHub Actions job that creates a backup and deploys to the hosting account. The cloud executor cannot directly reach public SSH servers; a GitHub hosted runner supplies the SSH connection.

## Prepared connection check

`.github/workflows/strim-access.yml` is a manually triggered connection check for `bot20@bot20.ftp.tools:22`. It reports directory names and installed tool paths. It does not change the remote site or read configuration values. It has no automatic push or pull-request trigger.

The check requires these GitHub Actions repository secrets:

- `STRIM_SSH_PRIVATE_KEY`: the private key matching the public key added to the hosting account. Transfer it through a secure secrets-setting operation; never commit it or paste it into chat.
- `STRIM_SSH_KNOWN_HOSTS`: the SSH server public host key in OpenSSH known_hosts format. Verify it through a trusted hosting connection before recording it. The job requires normal SSH host-key verification.

These are separate keys: the private client key authenticates the account; the server host key authenticates the hosting server.

## Remaining prerequisites

1. Confirm GitHub API access, repository visibility, and Actions permissions. The cloud environment needs HTTPS access to `api.github.com` to manage secrets, trigger the check, and inspect its result. Git read access alone does not confirm API or push access.
2. Execute the connection check and identify the actual document root and application runtime. No remote connection has been validated yet.
3. Import the source after identifying production secrets, databases, uploads, caches, and generated files. Keep production credentials out of Git. Inspect the repository's visibility before importing source.
4. Install the application's dependencies and run its existing build and relevant checks in the cloud environment. Use the existing checkout; each cloud task is already isolated, so do not create Git worktrees unless the user requests one.
5. Implement deployment for the verified document root and build output. Before replacing files, create a dated backup outside the public document root, verify that it can be read, and record the commit and affected paths. Preserve production configuration and runtime data.
6. After deployment, perform a representative HTTPS functional check. Restore the previous files if it fails and retain the backup and deployment log. Define database migration and restore procedures separately if changes affect the database.

The deployment job is not implemented yet. The application source, document root, server host key, API authorization, and remote connection must be established before its commands can be defined and tested safely. Do not infer successful setup from a saved configuration or a passing YAML parse.
