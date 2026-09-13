import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '..');
const src = path.join(root, 'node_modules', 'boxicons');
const dest = path.join(root, 'public', 'vendor', 'boxicons');

if (!fs.existsSync(path.join(src, 'css', 'boxicons.min.css'))) {
    console.error('boxicons not installed — run: npm install');
    process.exit(1);
}

fs.mkdirSync(path.join(dest, 'css'), { recursive: true });
fs.mkdirSync(path.join(dest, 'fonts'), { recursive: true });

fs.copyFileSync(
    path.join(src, 'css', 'boxicons.min.css'),
    path.join(dest, 'css', 'boxicons.min.css'),
);

for (const file of fs.readdirSync(path.join(src, 'fonts'))) {
    if (/\.(woff2?|ttf|eot|svg)$/i.test(file)) {
        fs.copyFileSync(path.join(src, 'fonts', file), path.join(dest, 'fonts', file));
    }
}

console.log('Copied boxicons → public/vendor/boxicons/');
