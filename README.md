# Vicunav Repository Template

Reusable starting point for repositories in the Vicunav ecosystem. Creating a
repository from this template copies its files and directory structure into a new,
independent repository without treating it as a fork.

## Included foundation

- `AGENTS.md` with placeholders for repository-specific instructions.
- `CONTRIBUTING.md` with the atomic issue and squash-merge workflow.
- `docs/standards/` pinned to the shared Vicunav standards.
- A structured atomic-task issue form.
- PHP linting with WordPress Coding Standards in GitHub Actions.
- GPL-2.0-or-later licensing suitable for WordPress themes and plugins.

## Creating a repository from this template

1. Open this template repository on GitHub.
2. Select **Use this template** and then **Create a new repository**.
3. Choose the owner, repository name, description, and visibility.
4. Select **Create repository**.
5. Clone the new repository, including its submodules:

   ```bash
   git clone --recurse-submodules https://github.com/OWNER/REPOSITORY.git
   cd REPOSITORY
   ```

If the repository was cloned without submodules, initialize them afterward:

```bash
git submodule update --init --recursive
```

## Required customization

After creating the repository:

1. Replace this README with project-specific documentation in English.
2. Replace every placeholder in `AGENTS.md` and document the actual validation
   commands.
3. Confirm that the standards submodule points to the intended commit.
4. Add the package bootstrap, tests, and tooling required by its contract.
5. Configure branch protection and allow only squash-merge pull requests into `main`.
6. Verify that no `{{PLACEHOLDER}}` values remain in versioned files.

Do not add product-specific files to this template merely because one consumer needs
them. Shared repository scaffolding belongs here; package behavior belongs in the new
repository.

For more information, see the GitHub guide on [creating a repository from a template](https://docs.github.com/en/repositories/creating-and-managing-repositories/creating-a-repository-from-a-template).
