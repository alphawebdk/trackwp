#!/usr/bin/env node
/**
 * TrackWP build pipeline.
 *
 * - Minifies all .js files under assets/ via esbuild → *.min.js
 * - Minifies all .css files under assets/ via PostCSS + cssnano → *.min.css
 * - Skips files already ending in .min.js / .min.css
 * - Supports --watch
 */

import { readdir, readFile, writeFile, stat } from 'node:fs/promises';
import { watch } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import esbuild from 'esbuild';
import postcss from 'postcss';
import cssnano from 'cssnano';

const __filename = fileURLToPath(import.meta.url);
const __dirname  = path.dirname(__filename);
const ASSETS_DIR = path.join(__dirname, 'assets');
const WATCH      = process.argv.includes('--watch');

/**
 * Recursively walk a directory and yield absolute file paths.
 */
async function walk(dir) {
    let entries;
    try {
        entries = await readdir(dir, { withFileTypes: true });
    } catch (err) {
        if (err.code === 'ENOENT') return [];
        throw err;
    }
    const files = [];
    for (const entry of entries) {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) {
            files.push(...(await walk(full)));
        } else if (entry.isFile()) {
            files.push(full);
        }
    }
    return files;
}

/**
 * Filter to source .js / .css files (excluding already-minified).
 */
function classify(files) {
    const js  = [];
    const css = [];
    for (const f of files) {
        const lower = f.toLowerCase();
        if (lower.endsWith('.min.js') || lower.endsWith('.min.css')) continue;
        if (lower.endsWith('.js'))  js.push(f);
        else if (lower.endsWith('.css')) css.push(f);
    }
    return { js, css };
}

function rel(p) {
    return path.relative(__dirname, p).replace(/\\/g, '/');
}

function pct(before, after) {
    if (before === 0) return '0';
    return (((before - after) / before) * 100).toFixed(1);
}

function summary(file, before, after) {
    console.log(`${rel(file)}  ${before}B → ${after}B  (-${pct(before, after)}%)`);
}

/**
 * Build a single JS file via esbuild build API.
 */
async function buildJsFile(file) {
    const outfile = file.replace(/\.js$/i, '.min.js');
    const src     = await readFile(file);
    await esbuild.build({
        entryPoints: [file],
        outfile,
        minify: true,
        target: 'es2017',
        bundle: false,
        legalComments: 'none',
        logLevel: 'silent',
    });
    const out = await stat(outfile);
    summary(file, src.length, out.size);
}

/**
 * Build a single CSS file via PostCSS + cssnano.
 */
async function buildCssFile(file) {
    const outfile = file.replace(/\.css$/i, '.min.css');
    const src     = await readFile(file, 'utf8');
    const result  = await postcss([cssnano({ preset: 'default' })]).process(src, {
        from: file,
        to:   outfile,
        map:  false,
    });
    await writeFile(outfile, result.css, 'utf8');
    summary(file, Buffer.byteLength(src, 'utf8'), Buffer.byteLength(result.css, 'utf8'));
}

async function buildAll() {
    const all          = await walk(ASSETS_DIR);
    const { js, css }  = classify(all);
    for (const f of js)  await buildJsFile(f);
    for (const f of css) await buildCssFile(f);
    return { js, css };
}

/**
 * Watch mode:
 * - esbuild context+watch for JS entry points
 * - fs.watch recursive for CSS, rebuilds on change
 */
async function watchAll() {
    const { js, css } = await (async () => {
        const all = await walk(ASSETS_DIR);
        return classify(all);
    })();

    // JS — one esbuild context per file with watch
    for (const file of js) {
        const outfile = file.replace(/\.js$/i, '.min.js');
        const ctx = await esbuild.context({
            entryPoints: [file],
            outfile,
            minify: true,
            target: 'es2017',
            bundle: false,
            legalComments: 'none',
            logLevel: 'info',
        });
        await ctx.watch();
        console.log(`watching JS: ${rel(file)}`);
    }

    // CSS — fs.watch recursive
    try {
        watch(ASSETS_DIR, { recursive: true }, async (eventType, filename) => {
            if (!filename) return;
            const full  = path.join(ASSETS_DIR, filename);
            const lower = full.toLowerCase();
            if (!lower.endsWith('.css') || lower.endsWith('.min.css')) return;
            try {
                await buildCssFile(full);
            } catch (err) {
                console.error(`CSS rebuild failed for ${rel(full)}:`, err.message);
            }
        });
        console.log('watching CSS in assets/ (recursive)');
    } catch (err) {
        console.error('fs.watch recursive failed:', err.message);
    }

    // Build CSS once up-front
    for (const f of css) {
        try { await buildCssFile(f); } catch (err) {
            console.error(`CSS build failed for ${rel(f)}:`, err.message);
        }
    }
}

(async () => {
    try {
        if (WATCH) {
            await watchAll();
            // keep process alive
        } else {
            await buildAll();
        }
    } catch (err) {
        console.error('Build failed:', err);
        process.exit(1);
    }
})();
