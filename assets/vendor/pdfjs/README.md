# pdf.js

Mozilla pdf.js `pdfjs-dist` 6.4.299 (Apache-2.0, see LICENSE): `build/pdf.min.mjs` and
`build/pdf.worker.min.mjs`, unchanged, renamed `.js` so that every web server serves them with a
JavaScript MIME type (some do not know `.mjs`). They stay ES modules: `invoices.js` loads them with
`import()` and a module worker.

Used by the live preview of the invoice settings only. To update: `npm pack pdfjs-dist@<version>`,
copy the two files again and change the version here.
