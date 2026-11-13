// 03_FRONTEND/src/assets/js/puente_favorito.js - Favorito "Vincular SGS" (2026-11-11). NO se carga en ninguna página: el SGS
// lo arma (sgsFavoritoPuente en config/puertos_cto.php) con su dirección en __SGS__ y la de Wincore en __WINCORE__, y la
// cuenta principal lo arrastra a la barra de favoritos de Chrome.
// Se pulsa estando en Wincore con la sesión iniciada: abre la ventana del vínculo del SGS y responde SOLO a esa ventana.
// Las consultas salen de esta pestaña con la sesión de Wincore (la cookie y el token no salen de aquí) y al SGS se
// devuelve únicamente el estado de la CTO y de sus puertos, nunca los datos de clientes.
(function () {
  var SGS = '__SGS__', WINCORE = '__WINCORE__', ORIGEN = new URL(SGS).origin;
  if (location.origin !== WINCORE) { alert('Primero abre Wincore, inicia sesión y luego pulsa este favorito.'); return; }
  // Si ya se había pulsado (la ventana quedó con un error, venció la sesión del SGS, etc.), se deja lo anterior y se vincula de
  // nuevo desde cero: la ventana del SGS se recarga (mismo nombre) y vuelve a saludar.
  if (window.__sgsFin) window.__sgsFin();

  // Token "ticked" de la página de Wincore (3 códigos md5 separados por punto)
  function token() {
    var re = /^[0-9a-f]{32}(\.[0-9a-f]{32}){2}$/i, el = document.querySelector('[name="ticked"],#ticked'), t = el && el.value || '';
    if (!t) { var els = document.querySelectorAll('input,textarea'); for (var k = 0; k < els.length; k++) if (re.test(els[k].value)) { t = els[k].value; break; } }
    if (!t) { var m = document.documentElement.innerHTML.match(/[0-9a-f]{32}\.[0-9a-f]{32}\.[0-9a-f]{32}/i); if (m) t = m[0]; }
    return t;
  }
  var CAMPOS = ['tx_mensaje_activacion', 'tx_cto_nap', 'txt_estado_cto_nap', 'tx_cluster', 'tx_direccion', 'tx_distrito', 'tx_provincia',
    'tx_departamento', 'tx_puertos_cto', 'tx_lista_puertos', 'btn_consultar_potencia', 'file_ordenamiento', 'tx_lista_img', 'tx_n_pedido',
    'tx_id_regtra', 'no_cliente', 'nu_documento', 'tx_dir_direccion', 'tx_dir_lat', 'tx_dir_lon', 'tx_puerto', 'tx_estado_puerto',
    'bt_act_cliente', 'bt_act_cancelar', 'tx_task_observaciones', 'nu_task_groupfile', 'tx_id_sitio', 'tx_user_name_actualizo',
    'tx_fh_crea_actualizo', 'tx_in_estado', 'cod_sitio', 'tx_nodo', 'tx_mac_registradas', 'tx_id_cto', 'tx_lat_cto', 'tx_lon_cto',
    'tx_json_puertos_ini', 'tx_json_puertos', 'ap_cliente', 'tx_email', 'tx_celular', 'tx_id_cajas', 'tx_json_lista_img', 'ticked'];
  function parametros(extra) {
    var p = {}; CAMPOS.forEach(function (k) { p[k] = ''; });
    Object.keys(extra).forEach(function (k) { p[k] = extra[k] == null ? '' : extra[k]; });
    p.ticked = token();
    return p;
  }
  function rpc(metodo, params) {
    return fetch('/php/index2.php', { method: 'POST', credentials: 'include',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({ method: metodo, params: params }) })
      .then(function (r) { return r.text(); })
      .then(function (t) { try { return JSON.parse(t); } catch (e) { throw new Error('Wincore no respondió datos: puede que la sesión de Wincore haya vencido.'); } });
  }
  function consultar(cod) {
    return rpc('logordenamientoplanta.getdatacto', parametros({ tx_cto_nap: cod, cod_sitio: cod })).then(function (info) {
      var cto = info && info.data && info.data[0];
      if (!cto) throw new Error('No se encontró la CTO ' + cod + ' en Wincore.');
      var sitio = cto.tx_id_sitio || cto.id_sitio || cto.tx_id_cto || cto.id_cto || '';
      var extra = {}; Object.keys(cto).forEach(function (k) { extra[k] = cto[k]; });
      extra.tx_cto_nap = cod; extra.cod_sitio = cod; extra.id_sitio = sitio; extra.tx_id_sitio = sitio;
      return rpc('logordenamientoplanta.getpuertos', parametros(extra)).then(function (pr) {
        if (!pr || !Array.isArray(pr.data) || !pr.data.length) throw new Error('Wincore no devolvió los puertos de la CTO ' + cod + '.');
        var p0 = pr.data[0];
        // MAC de activación tal como viene (Wincore la busca así); solo para cruzar la respuesta se pasa a mayúsculas. Nunca el campo
        // "mac": a veces viene en otro formato (ZTEG…) y no coincide con la potencia.
        var macDe = function (p) { return String(p.mac_prefijo_activacion || ''); };
        // Solo lo que se muestra: nada de clientes (la MAC solo sirve aquí para pedir la potencia y no se envía al SGS)
        var armar = function (potencias) {
          return { cto: { estado: cto.txt_estado_cto_nap || '', objeto: p0.objeto || '', direccion: cto.tx_direccion || '', distrito: cto.tx_distrito || '',
              provincia: cto.tx_provincia || '', departamento: cto.tx_departamento || '', lat: cto.tx_lat_cto || p0.n_latcto || '', lon: cto.tx_lon_cto || p0.n_loncto || '' },
            puertos: pr.data.map(function (p) {
              var mac = p.estado_puerto === 'OCUPADO' ? macDe(p) : '', pot = mac ? potencias[mac.toUpperCase()] : null;
              return { puerto: p.puerto, estado: p.estado_puerto, servicio: p.estado_unifilar || '', potencia: pot == null ? '' : pot };
            }) };
        };
        // Potencia de cada puerto ocupado (en el equipo del cliente, dBm), por su MAC de activación (mac_prefijo_activacion; si no
        // viene, no se pide). Solo se piden los ocupados (los libres no la muestran). A veces la primera respuesta de Wincore trae 0 en
        // todos y una segunda, unos segundos después, ya trae la medición: si todo vino en 0 o vacío, se pregunta una vez más.
        // Si falla, se muestran los puertos sin ella.
        var macs = pr.data.filter(function (p) { return p.estado_puerto === 'OCUPADO' && macDe(p); }).map(function (p) { return { nserie: macDe(p) }; });
        if (!macs.length) return armar({});
        var pedir = function (intento) {
          return rpc('logconodered.getInfoPotenciaByMacs', { allnserie: macs, ticked: token() }).then(function (po) {
            var potencias = {}, conValor = 0;
            (po && po.data || []).forEach(function (d) {
              if (!d.mac_serie) return;
              // Equipo apagado (estado_red inactive) = sin señal (0), aunque traiga una medición vieja
              var valor = String(d.estado_red || '').toLowerCase() === 'inactive' ? 0 : d.potencia_ont;
              potencias[String(d.mac_serie).toUpperCase()] = valor;
              if (Number(valor)) conValor++;
            });
            console.info('SGS · potencia de Wincore (intento ' + intento + '):', po && po.status, (po && po.data || []).length, 'filas,', conValor, 'con valor, para', macs.length, 'MAC');
            if (!conValor && intento === 1) return new Promise(function (ok) { setTimeout(ok, 3000); }).then(function () { return pedir(2); });
            return armar(potencias);
          });
        };
        return pedir(1).then(null, function (err) { console.info('SGS · no se pudo leer la potencia:', err.message); return armar({}); });
      });
    });
  }

  var marca = document.createElement('div');
  marca.textContent = 'Vinculado al SGS';
  marca.style.cssText = 'position:fixed;right:12px;bottom:12px;z-index:99999;padding:6px 12px;border-radius:999px;background:#FF5A0A;color:#fff;font:600 13px Arial,sans-serif;box-shadow:0 2px 8px rgba(0,0,0,.2)';
  var w = window.open(SGS + 'puente_wincore.php', 'sgs_puente', 'width=460,height=640');
  if (!w) { alert('Chrome bloqueó la ventana del SGS: permite las ventanas emergentes de Wincore y vuelve a pulsar el favorito.'); return; }
  document.body.appendChild(marca);
  function enviar(datos) { w.postMessage(datos, ORIGEN); }
  function oir(e) {
    if (e.origin !== ORIGEN || e.source !== w || !e.data || !e.data.sgs) return;
    var d = e.data;
    if (d.sgs === 'hola') enviar({ sgs: 'listo', token: !!token() });
    else if (d.sgs === 'ping') enviar({ sgs: 'pong' });
    else if (d.sgs === 'consultar' && /^[A-Z0-9][A-Z0-9_-]{2,39}$/.test(String(d.codigo)))
      consultar(String(d.codigo)).then(function (r) { enviar({ sgs: 'resultado', id: d.id, datos: r }); },
        function (err) { enviar({ sgs: 'resultado', id: d.id, datos: { error: err.message } }); });
    else if (d.sgs === 'fin') window.__sgsFin();
  }
  window.addEventListener('message', oir);
  window.__sgsFin = function () { window.removeEventListener('message', oir); marca.remove(); window.__sgsFin = null; };
})();
