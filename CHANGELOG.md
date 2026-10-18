# Changelog

## 1.0.0 (2026-10-18)

- First release.
- Works with Pterodactyl 2.0 (eggs and tags) and 1.x (nests); the version is detected automatically.
- Same server settings and product options as Paymenter's built-in Pterodactyl extension, so existing servers switch with one command.
- Egg tag filter on the product page, preselected from the nest a 1.x product used.
- Node reservation tags for automatic deployment on Pterodactyl 2.0.
- Lists that span several pages (more than 100 nodes, locations or eggs) are read completely.
- Clearer error messages with the HTTP status and the panel's reason.
- Fixed: a port array without `SERVER_PORT` now gives a clear error instead of a PHP warning.
