const CACHE_NAME = 'blufast_v1';
// Solo cacheamos la estructura base, nunca las peticiones AJAX ni los envíos de datos
const ASSETS = [ './index.php' ];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE_NAME).then(cache => cache.addAll(ASSETS)));
});

self.addEventListener('fetch', e => {
  // Truco clave: Si la petición no es de tipo GET (como el POST del login o los archivos subidos), 
  // la dejamos pasar directo a internet sin que el Service Worker se meta.
  if (e.request.method !== 'GET') {
    return;
  }
  
  e.respondWith(
    fetch(e.request).catch(() => caches.match(e.request))
  );
});