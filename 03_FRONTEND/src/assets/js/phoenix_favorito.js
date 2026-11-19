// 03_FRONTEND/src/assets/js/phoenix_favorito.js - Favorito "Vincular SGS – Phoenix" (2026-11-13). NO se carga en ninguna página: el
// SGS lo arma (sgsFavoritoPhoenix en config/puente_phoenix.php) con su dirección en __SGS__ y la de Phoenix en __PHOENIX__, y la
// cuenta principal lo arrastra a la barra de favoritos de Chrome.
// Se pulsa estando en Phoenix con la sesión iniciada: abre la ventana del vínculo del SGS y responde SOLO a esa ventana. Las
// lecturas salen de esta pestaña con la sesión de Phoenix (la contraseña y la cookie no salen de aquí) y SOLO a las rutas de la
// grilla de órdenes: aunque el SGS pidiera otra cosa, no se hace.
(function () {
  var SGS = '__SGS__', PHOENIX = '__PHOENIX__', ORIGEN = new URL(SGS).origin;
  if (location.origin !== PHOENIX) { alert('Primero abre Phoenix, inicia sesión y luego pulsa este favorito.'); return; }
  // Si ya se había pulsado, se deja lo anterior y se vincula de nuevo desde cero.
  if (window.__sgsFinPhoenix) window.__sgsFinPhoenix();

  var PERMITIDAS = ['/default.aspx/CargarComboJsonX', '/Paginas/OrdenTrabajo/grilla.aspx?to=1&nombre=Ordenes+de+Trabajo&id=5&icono=&edit=S',
    '/Paginas/OrdenTrabajo/Grilla.aspx/cargarGrilla'];
  function paso(p) {
    if (PERMITIDAS.indexOf(p.ruta) < 0) return Promise.reject(new Error('El SGS pidió una lectura no permitida.'));
    var op = { credentials: 'include', headers: { 'X-Requested-With': 'XMLHttpRequest' } };
    if (p.json) {
      op.method = 'POST';
      op.headers['Content-Type'] = 'application/json; charset=UTF-8';
      op.headers.Accept = 'application/json, text/javascript, */*; q=0.01';
      op.body = JSON.stringify(p.json);
    }
    return fetch(p.ruta, op).then(function (r) {
      if (/login\.aspx/i.test(r.url)) throw new Error('Tu sesión de Phoenix venció: vuelve a iniciar sesión en Phoenix y pulsa el favorito.');
      return r.text();
    });
  }
  // Los pasos de un pedido van uno tras otro (la grilla necesita que antes se abra su pantalla).
  function atender(pasos) {
    var textos = [];
    return pasos.reduce(function (cadena, p) {
      return cadena.then(function () { return paso(p); }).then(function (t) { textos.push(t); });
    }, Promise.resolve()).then(function () { return { textos: textos }; });
  }

  var marca = document.createElement('div');
  marca.textContent = 'Vinculado al SGS';
  marca.style.cssText = 'position:fixed;right:12px;bottom:12px;z-index:99999;padding:6px 12px;border-radius:999px;background:#FF5A0A;color:#fff;font:600 13px Arial,sans-serif;box-shadow:0 2px 8px rgba(0,0,0,.2)';
  var w = window.open(SGS + 'puente_phoenix.php', 'sgs_puente_phoenix', 'width=460,height=560');
  if (!w) { alert('Chrome bloqueó la ventana del SGS: permite las ventanas emergentes de Phoenix y vuelve a pulsar el favorito.'); return; }
  document.body.appendChild(marca);
  function enviar(datos) { w.postMessage(datos, ORIGEN); }
  function oir(e) {
    if (e.origin !== ORIGEN || e.source !== w || !e.data || !e.data.sgs) return;
    var d = e.data;
    if (d.sgs === 'hola') enviar({ sgs: 'listo' });
    else if (d.sgs === 'ping') enviar({ sgs: 'pong' });
    else if (d.sgs === 'pedido' && Array.isArray(d.pasos))
      atender(d.pasos).then(function (r) { enviar({ sgs: 'respuesta', id: d.id, datos: r }); },
        function (err) { enviar({ sgs: 'respuesta', id: d.id, datos: { error: err.message } }); });
    else if (d.sgs === 'fin') window.__sgsFinPhoenix();
  }
  window.addEventListener('message', oir);
  window.__sgsFinPhoenix = function () { window.removeEventListener('message', oir); marca.remove(); window.__sgsFinPhoenix = null; };
})();
