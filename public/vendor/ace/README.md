# Ace 1.44.0

Vendored from the `ace-builds` npm package, with its SHA-512 integrity verified against the registry metadata. Upstream: https://github.com/ajaxorg/ace-builds. License: BSD (see LICENSE).

The JavaScript files are the unmodified `src-noconflict` builds. Only supported language modes, the Chrome theme and the search extension are included. No CDN request is needed at runtime. Syntax workers are disabled.

`editor.css` is generated from those builds using `node scripts/vendor-editor.mjs`. It allows strict Content Security Policy without enabling inline stylesheets or inline scripts.
