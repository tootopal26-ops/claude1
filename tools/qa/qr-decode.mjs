// Decode QR PNGs with an independent decoder (jsQR). Usage: node tools/qa/qr-decode.mjs a.png b.png …  → JSON array of decoded texts (null when unreadable).
import { readFileSync } from 'node:fs';
import { decodeQr } from './site.mjs';
console.log(JSON.stringify(process.argv.slice(2).map((f) => decodeQr(readFileSync(f)))));
