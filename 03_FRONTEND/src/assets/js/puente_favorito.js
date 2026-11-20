// 03_FRONTEND/src/assets/js/puente_favorito.js - Favorito "Vincular SGS" (2026-11-11; 2026-11-20 ventana propia). NO se carga en
// ninguna página: el SGS lo arma (sgsFavoritoPuente en config/puertos_cto.php) con su dirección en __SGS__, la de Wincore en
// __WINCORE__ y la clave del programa en __CLAVE__ (se genera en Consultar puertos), y la cuenta principal lo arrastra a la barra
// de favoritos de Chrome de su laptop (con la VPN).
// 2026-11-20 (pedido del usuario: "se me pierde la conexión cuando uso Wincore"): al pulsarlo en Wincore abre una ventanita
// propia de Wincore, «Vínculo Wincore», y el trabajo pasa a ELLA. La pestaña donde se pulsó queda libre: se puede recargar,
// navegar o usar Wincore sin cortar el vínculo. La ventanita habla directo con el SGS (api/puertos_agente.php con la clave;
// el SGS le permite CORS solo a Wincore) y hace las consultas con la sesión de Wincore, que comparte con la otra pestaña. Al SGS
// solo va el estado de la CTO y de sus puertos, nunca datos de clientes. Basta con minimizarla; si se cierra, se corta.
(function () {
  var SGS = '__SGS__', WINCORE = '__WINCORE__', CLAVE = '__CLAVE__';
  if (location.origin !== WINCORE) { alert('Primero abre Wincore (con la VPN), inicia sesión y luego pulsa este favorito.'); return; }
  var tokenDe = function (doc) {
    var m = doc.documentElement.innerHTML.match(/[0-9a-f]{32}\.[0-9a-f]{32}\.[0-9a-f]{32}/i);
    return m ? m[0] : '';
  };
  var tk = tokenDe(document);
  if (!tk) { alert('No se encontró tu sesión de Wincore en esta pantalla: inicia sesión, entra a Wincore y vuelve a pulsar el favorito.'); return; }
  // Ventana con nombre fijo: si ya existe y está vinculada, solo se muestra (no se abre otra ni se corta la que funciona)
  var w = window.open('', 'sgs_vinculo_wincore', 'width=440,height=330');
  if (!w) { alert('Chrome bloqueó la ventana del vínculo: permite las ventanas emergentes de Wincore y vuelve a pulsar el favorito.'); return; }
  try { if (w.__sgsVinculo) { w.focus(); return; } } catch (e) { }
  w.location.href = location.href;
  var intentos = 0;
  (function esperar() {
    var lista = false;
    try { lista = w.location.origin === WINCORE && w.document.readyState === 'complete' && !!w.document.body; } catch (e) { }
    if (lista) {
      var s = w.document.createElement('script');
      s.textContent = '(' + PUENTE.toString() + ')(' + JSON.stringify({ sgs: SGS, clave: CLAVE, ticked: tokenDe(w.document) || tk }) + ');';
      w.document.head.appendChild(s);
      return;
    }
    if (++intentos > 80) { alert('La ventana del vínculo no terminó de abrir. Vuelve a pulsar el favorito.'); return; }
    setTimeout(esperar, 500);
  })();

  // Todo lo de aquí abajo corre DENTRO de la ventanita «Vínculo Wincore».
  function PUENTE(cfg) {
    if (window.__sgsVinculo) return;
    window.__sgsVinculo = true;
    var SGS = cfg.sgs.replace(/\/+$/, '') + '/', CLAVE = cfg.clave, ticked = cfg.ticked, SONDEO = 8000;
    var atendidas = 0, ocupado = false, activo = false, fallosSesion = 0, fallosSgs = 0, reloj = null, intervalo = null;
    document.title = 'Vínculo Wincore · SGS';
    document.body.innerHTML = '<div style="font:14px Arial,sans-serif;color:#141414;padding:18px;max-width:420px">'
      + '<div style="font-weight:700;font-size:16px;color:#FF5A0A;margin-bottom:8px">Vínculo Wincore · SGS</div>'
      + '<div id="vwEstado" style="font-weight:600">Conectando con el SGS…</div>'
      + '<div id="vwDetalle" style="color:#6B6D70;font-size:12.5px;margin-top:6px"></div>'
      + '<p style="color:#6B6D70;font-size:12.5px;margin:14px 0 10px">Minimiza esta ventana y no la cierres. Puedes seguir usando Wincore en tu otra ventana.</p>'
      + '<button id="vwParar" type="button" style="padding:6px 14px;border:1px solid #B42318;color:#B42318;background:#fff;border-radius:8px;cursor:pointer">Desactivar</button></div>';
    var $ = function (id) { return document.getElementById(id); };
    var estado = function (texto, color) { $('vwEstado').textContent = texto; $('vwEstado').style.color = color || '#141414'; };
    var detalle = function (texto) { $('vwDetalle').textContent = texto; };
    var hora = function () { return new Date().toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit' }); };

    function api(accion, datos) {
      return fetch(SGS + 'api/puertos_agente.php?action=' + accion, { method: datos ? 'POST' : 'GET', headers: { 'X-SGS-Clave': CLAVE },
        body: datos ? new URLSearchParams(datos) : undefined, credentials: 'omit' })
        .then(function (r) {
          return r.json().catch(function () { return {}; }).then(function (d) {
            if (!r.ok || d.status !== 'success') throw new Error(d.message || 'El SGS no respondió (' + r.status + ').');
            return d;
          });
        });
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
      p.ticked = ticked;
      return p;
    }
    function rpc(metodo, params) {
      return fetch('/php/index2.php', { method: 'POST', credentials: 'include',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ method: metodo, params: params }) })
        .then(function (r) { return r.text(); })
        .then(function (t) { try { return JSON.parse(t); } catch (e) { var x = new Error('Wincore no respondió datos: tu sesión de Wincore pudo vencer.'); x.sesion = true; throw x; } });
    }
    // Misma lógica que antes (validada contra la supervisión en vivo de Wincore, 2026-11-13): OCUPADO sin MAC = DISPONIBLE; activo
    // o suspendido por el estado en vivo de la potencia (estado_red) y, si no llegó, por estado_unifilar. La MAC no sale de aquí.
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
          var macDe = function (p) { return String(p.mac_prefijo_activacion || '').trim(); };
          var armar = function (potencias) {
            return { cto: { estado: cto.txt_estado_cto_nap || '', objeto: p0.objeto || '', direccion: cto.tx_direccion || '', distrito: cto.tx_distrito || '',
                provincia: cto.tx_provincia || '', departamento: cto.tx_departamento || '', lat: cto.tx_lat_cto || p0.n_latcto || '', lon: cto.tx_lon_cto || p0.n_loncto || '' },
              puertos: pr.data.map(function (p) {
                var mac = p.estado_puerto === 'OCUPADO' ? macDe(p) : '', pot = mac ? potencias[mac.toUpperCase()] : null;
                if (p.estado_puerto === 'OCUPADO' && !mac) return { puerto: p.puerto, estado: 'DISPONIBLE', servicio: '', potencia: '' };
                var servicio = pot && pot.red === 'active' ? 'ACTIVO' : pot && pot.red === 'inactive' ? 'SUSPENDIDO' : p.estado_unifilar || '';
                return { puerto: p.puerto, estado: p.estado_puerto, servicio: mac ? servicio : p.estado_unifilar || '', potencia: pot ? pot.valor : '' };
              }) };
          };
          var macs = pr.data.filter(function (p) { return p.estado_puerto === 'OCUPADO' && macDe(p); }).map(function (p) { return { nserie: macDe(p) }; });
          if (!macs.length) return armar({});
          var pedir = function (intento) {
            return rpc('logconodered.getInfoPotenciaByMacs', { allnserie: macs, ticked: ticked }).then(function (po) {
              var potencias = {}, conValor = 0;
              (po && po.data || []).forEach(function (d) {
                if (!d.mac_serie) return;
                var red = String(d.estado_red || '').toLowerCase(), valor = red === 'inactive' ? 0 : d.potencia_ont;
                potencias[String(d.mac_serie).toUpperCase()] = { valor: valor == null ? '' : valor, red: red };
                if (Number(valor)) conValor++;
              });
              if (!conValor && intento === 1) return new Promise(function (ok) { setTimeout(ok, 3000); }).then(function () { return pedir(2); });
              return armar(potencias);
            });
          };
          return pedir(1).then(null, function (err) { if (err.sesion) throw err; return armar({}); });
        });
      });
    }

    function apagar(texto) {
      activo = false;
      if (reloj) reloj.postMessage(0);
      if (intervalo) clearInterval(intervalo);
      estado(texto, '#B42318');
      $('vwParar').style.display = 'none';
    }
    // Pide al SGS la siguiente consulta (una a la vez); el pedido también avisa que la ventanita sigue viva.
    function vuelta() {
      if (!activo || ocupado) return;
      ocupado = true;
      var hubo = false;
      api('tomar').then(function (t) {
        if (!activo) return;                       // se desactivó mientras llegaba la respuesta
        fallosSgs = 0;
        if (!t.activo) { apagar('El vínculo se desactivó o venció. Pulsa el favorito en Wincore para activarlo otra vez.'); return; }
        estado('Vinculado hasta las ' + t.vence, '#067647');
        if (!t.consulta) return;
        var c = t.consulta;
        hubo = true;
        return consultar(String(c.codigo)).then(function (datos) { fallosSesion = 0; return datos; }, function (err) {
          if (err.sesion) fallosSesion++;
          return { error: err.sesion ? 'La consulta de puertos se interrumpió. Vuelve a intentarlo en un momento.' : err.message };
        }).then(function (datos) {
          return api('responder', { id: c.id, datos: JSON.stringify(datos) }).then(function () {
            atendidas++;
            detalle(atendidas + (atendidas === 1 ? ' consulta atendida' : ' consultas atendidas') + ' · la última a las ' + hora() + (t.en_espera ? ' · en espera: ' + t.en_espera : ''));
          });
        }).then(function () {
          if (fallosSesion >= 2) {
            api('desactivar', {}).catch(function () { });
            apagar('Tu sesión de Wincore se cerró. Inicia sesión en Wincore y vuelve a pulsar el favorito.');
          }
        });
      }).catch(function (e) {
        if (++fallosSgs >= 8) apagar('Sin conexión con el SGS hace un buen rato (' + e.message + '). Vuelve a pulsar el favorito.');
        else estado('Sin respuesta del SGS, reintentando…', '#B54708');
      }).then(function () {
        ocupado = false;
        if (hubo && activo) setTimeout(vuelta, 300);   // después de una consulta, la siguiente de la cola sin esperar
      });
    }

    $('vwParar').addEventListener('click', function () {
      api('desactivar', {}).catch(function () { }).then(function () { apagar('Vínculo desactivado. Nadie puede consultar puertos hasta que lo actives de nuevo.'); });
    });

    api('vincular', {}).then(function (v) {
      activo = true;
      estado('Vinculado hasta las ' + v.vence, '#067647');
      detalle('Esperando consultas de los supervisores…');
      // Reloj en un Worker: Chrome frena los temporizadores de las ventanas minimizadas (hasta 1 por minuto).
      try {
        reloj = new Worker(URL.createObjectURL(new Blob(['onmessage=function(e){clearInterval(self.t);if(e.data>0)self.t=setInterval(function(){postMessage(1)},e.data)}'], { type: 'text/javascript' })));
        reloj.onmessage = vuelta;
        reloj.postMessage(SONDEO);
      } catch (e) { intervalo = setInterval(vuelta, SONDEO); }
      vuelta();
    }, function (e) {
      apagar(e.message);
    });
  }
})();
