process.env.RAYON_NUM_THREADS ??= '1';

await import(new URL('../node_modules/vite/bin/vite.js', import.meta.url));
