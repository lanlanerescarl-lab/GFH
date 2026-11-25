# Organization-local extensions

One folder per extension, `extensions/<key>/` here, each with `extension.json` and its entry file. An extension here always loads for this organization and needs no feature key. If its key matches a shared extension in the top-level `extensions/` folder, this one replaces it for this organization.

How to write one: `docs/extension-guide.md`.
