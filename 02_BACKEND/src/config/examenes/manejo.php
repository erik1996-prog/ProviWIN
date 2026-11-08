<?php
// 02_BACKEND/src/config/examenes/manejo.php - Referente "Manejo defensivo y seguridad vial" (2026-11-05).
// Pedido del usuario: hubo accidentes de técnicos al movilizarse entre zonas (choques). Camionetas, autos y motos de las
// cuadrillas: inspección antes de salir, velocidad, distracciones, fatiga, alcohol, clima, carga (escaleras), accidentes y
// viajes a otras zonas. Velocidades del Reglamento Nacional de Tránsito (zona urbana) y números de emergencia del Perú.
// Misma forma que altura.php (ver sus comentarios). Sin fotos por ahora: si se agregan ('imagen' / 'imagenes'), cuentan
// para el mínimo de preguntas con foto.
return [
    'codigo' => 'manejo',
    'nombre' => 'Manejo defensivo',
    'descripcion' => 'Seguridad vial al movilizarse entre zonas: vehículo, velocidad, distracciones, fatiga, alcohol, carga y accidentes.',
    'preguntas' => [
        // ---------- Inspección del vehículo ----------
        ['id' => 'MAN01', 'tema' => 'Inspección del vehículo', 'tipo' => 'multiple',
         'texto' => 'Antes de salir con la unidad, ¿qué debes revisar?',
         'opciones' => ['Llantas: presión y desgaste', 'Frenos y luces', 'SOAT, revisión técnica y tu licencia vigentes', 'Que el tanque esté lleno hasta el tope'], 'correcta' => [0, 1, 2]],
        ['id' => 'MAN02', 'tema' => 'Inspección del vehículo', 'tipo' => 'vf',
         'texto' => 'Si una luz de freno no funciona, puedes salir igual y arreglarla al regresar.',
         'opciones' => ['Verdadero', 'Falso'], 'correcta' => [1]],
        ['id' => 'MAN03', 'tema' => 'Inspección del vehículo', 'tipo' => 'multiple',
         'texto' => '¿Qué equipo de emergencia debe llevar la unidad?',
         'opciones' => ['Extintor vigente', 'Botiquín', 'Triángulos o conos de seguridad', 'Parlantes para música'], 'correcta' => [0, 1, 2]],
        ['id' => 'MAN04', 'tema' => 'Inspección del vehículo', 'tipo' => 'caso',
         'texto' => 'Al revisar la camioneta antes de viajar a otra provincia ves una llanta muy gastada. ¿Qué haces?',
         'opciones' => ['No salgo: aviso al supervisor para cambiarla antes de viajar', 'Salgo despacio y la cambio allá', 'Le bajo un poco el aire para que dure', 'Viajo de noche para que no haga calor'], 'correcta' => [0]],

        // ---------- Velocidad y distancia ----------
        ['id' => 'MAN05', 'tema' => 'Velocidad y distancia', 'tipo' => 'unica',
         'texto' => 'En el Perú, ¿cuál es la velocidad máxima en una zona escolar o de hospitales?',
         'opciones' => ['30 km/h', '50 km/h', '60 km/h', '80 km/h'], 'correcta' => [0]],
        ['id' => 'MAN06', 'tema' => 'Velocidad y distancia', 'tipo' => 'unica',
         'texto' => 'En la ciudad, ¿cuál es la velocidad máxima en una avenida (si no hay otra señal)?',
         'opciones' => ['60 km/h', '40 km/h', '80 km/h', '100 km/h'], 'correcta' => [0]],
        ['id' => 'MAN07', 'tema' => 'Velocidad y distancia', 'tipo' => 'unica',
         'texto' => '¿Qué distancia debes dejar con el vehículo de adelante en condiciones normales?',
         'opciones' => ['Al menos 3 segundos', 'Medio segundo', 'Un metro', 'Lo más cerca posible para que no se metan'], 'correcta' => [0]],
        ['id' => 'MAN08', 'tema' => 'Velocidad y distancia', 'tipo' => 'vf',
         'texto' => 'Si vas tarde a una atención, está permitido pasar el límite de velocidad.',
         'opciones' => ['Verdadero', 'Falso'], 'correcta' => [1]],

        // ---------- Distracciones y fatiga ----------
        ['id' => 'MAN09', 'tema' => 'Distracciones y fatiga', 'tipo' => 'caso',
         'texto' => 'Vas manejando y el supervisor te llama al celular para darte una nueva dirección. ¿Qué haces?',
         'opciones' => ['Me estaciono en un lugar seguro y recién contesto', 'Contesto con una mano y sigo', 'Escribo la dirección mientras manejo despacio', 'Le paso el celular al compañero y yo leo la pantalla'], 'correcta' => [0]],
        ['id' => 'MAN10', 'tema' => 'Distracciones y fatiga', 'tipo' => 'multiple',
         'texto' => '¿Cuáles son señales de cansancio al manejar?',
         'opciones' => ['Bostezar seguido y parpadear mucho', 'Salirte del carril sin darte cuenta', 'No recordar los últimos kilómetros', 'Tener hambre'], 'correcta' => [0, 1, 2]],
        ['id' => 'MAN11', 'tema' => 'Distracciones y fatiga', 'tipo' => 'caso',
         'texto' => 'Regresas de otra zona en la noche y empiezas a cabecear. ¿Qué haces?',
         'opciones' => ['Me detengo en un lugar seguro a descansar y aviso al supervisor', 'Bajo la ventana y sigo', 'Subo el volumen de la música', 'Acelero para llegar antes'], 'correcta' => [0]],
        ['id' => 'MAN12', 'tema' => 'Distracciones y fatiga', 'tipo' => 'vf',
         'texto' => 'Revisar el GPS o los mensajes en un semáforo en rojo es seguro, porque el vehículo está detenido.',
         'opciones' => ['Verdadero', 'Falso'], 'correcta' => [1]],

        // ---------- Alcohol y drogas ----------
        ['id' => 'MAN13', 'tema' => 'Alcohol y drogas', 'tipo' => 'unica',
         'texto' => '¿Cuánto alcohol puedes tomar antes de manejar la unidad de trabajo?',
         'opciones' => ['Nada: cero alcohol', 'Una cerveza', 'Dos cervezas si comes algo', 'Lo que quieras si te sientes bien'], 'correcta' => [0]],
        ['id' => 'MAN14', 'tema' => 'Alcohol y drogas', 'tipo' => 'vf',
         'texto' => 'Algunos medicamentos (por ejemplo, para la gripe o la alergia) dan sueño y pueden impedir manejar con seguridad.',
         'opciones' => ['Verdadero', 'Falso'], 'correcta' => [0]],
        ['id' => 'MAN15', 'tema' => 'Alcohol y drogas', 'tipo' => 'caso',
         'texto' => 'Tu compañero, que debe manejar, llega con olor a alcohol de la noche anterior. ¿Qué haces?',
         'opciones' => ['No dejo que maneje y aviso al supervisor', 'Lo dejo manejar despacio', 'Le doy un café y que maneje', 'No digo nada para no tener problemas'], 'correcta' => [0]],

        // ---------- Clima y vía ----------
        ['id' => 'MAN16', 'tema' => 'Clima y vía', 'tipo' => 'multiple',
         'texto' => 'Empieza a llover fuerte en la carretera. ¿Qué debes hacer?',
         'opciones' => ['Bajar la velocidad', 'Aumentar la distancia con el de adelante', 'Encender las luces bajas', 'Frenar fuerte en las curvas'], 'correcta' => [0, 1, 2]],
        ['id' => 'MAN17', 'tema' => 'Clima y vía', 'tipo' => 'unica',
         'texto' => 'Con neblina, ¿qué luces usas?',
         'opciones' => ['Luces bajas (y neblineros si tiene)', 'Luces altas', 'Ninguna, para no encandilar', 'Solo las intermitentes todo el camino'], 'correcta' => [0]],
        ['id' => 'MAN18', 'tema' => 'Clima y vía', 'tipo' => 'vf',
         'texto' => 'Está prohibido adelantar en curvas, puentes y donde hay línea continua.',
         'opciones' => ['Verdadero', 'Falso'], 'correcta' => [0]],

        // ---------- Carga y escaleras ----------
        ['id' => 'MAN19', 'tema' => 'Carga y escaleras', 'tipo' => 'caso',
         'texto' => 'Vas a llevar la escalera telescópica en el porta escalera de la camioneta. ¿Qué es lo correcto?',
         'opciones' => ['Bien amarrada en dos puntos, revisada antes de salir y señalizada si sobresale', 'Apoyada sin amarrar porque el viaje es corto', 'Que el compañero la sostenga con la mano por la ventana', 'Amarrada con un solo cable delgado'], 'correcta' => [0]],
        ['id' => 'MAN20', 'tema' => 'Carga y escaleras', 'tipo' => 'vf',
         'texto' => 'Las herramientas sueltas dentro de la cabina pueden golpear a los ocupantes en un frenazo.',
         'opciones' => ['Verdadero', 'Falso'], 'correcta' => [0]],
        ['id' => 'MAN21', 'tema' => 'Carga y escaleras', 'tipo' => 'unica',
         'texto' => '¿Quiénes deben usar el cinturón de seguridad?',
         'opciones' => ['Todos los ocupantes, adelante y atrás', 'Solo el conductor', 'Solo en carretera', 'Solo si el viaje es largo'], 'correcta' => [0]],

        // ---------- Accidentes ----------
        ['id' => 'MAN22', 'tema' => 'Accidentes', 'tipo' => 'orden',
         'texto' => 'Tuviste un choque. Ordena lo que haces, del primero al último.',
         'opciones' => ['Detener el vehículo y encender las luces de emergencia', 'Señalizar con triángulos o conos', 'Ver si hay heridos y llamar a emergencias', 'Avisar al supervisor y no retirarte del lugar'], 'correcta' => []],
        ['id' => 'MAN23', 'tema' => 'Accidentes', 'tipo' => 'unica',
         'texto' => 'En el Perú, ¿a qué número llamas a los bomberos?',
         'opciones' => ['116', '105', '106', '102'], 'correcta' => [0]],
        ['id' => 'MAN24', 'tema' => 'Accidentes', 'tipo' => 'vf',
         'texto' => 'A un herido en un choque hay que moverlo de inmediato, aunque no haya peligro de incendio.',
         'opciones' => ['Verdadero', 'Falso'], 'correcta' => [1]],
        ['id' => 'MAN25', 'tema' => 'Accidentes', 'tipo' => 'caso',
         'texto' => 'Tocaste levemente un auto estacionado y no hay nadie. ¿Qué haces?',
         'opciones' => ['Me detengo, tomo fotos, aviso al supervisor y dejo mis datos', 'Me voy porque fue poco', 'Espero un minuto y me voy', 'Solo lo cuento si me preguntan'], 'correcta' => [0]],

        // ---------- Motocicleta ----------
        ['id' => 'MAN26', 'tema' => 'Motocicleta', 'tipo' => 'multiple',
         'texto' => 'Si te movilizas en moto, ¿qué es obligatorio?',
         'opciones' => ['Casco certificado y abrochado', 'Casco también para el acompañante', 'Chaleco o ropa reflectiva', 'Llevar la escalera sobre el hombro'], 'correcta' => [0, 1, 2]],
        ['id' => 'MAN27', 'tema' => 'Motocicleta', 'tipo' => 'vf',
         'texto' => 'En moto está bien pasar entre dos carros en movimiento para avanzar más rápido.',
         'opciones' => ['Verdadero', 'Falso'], 'correcta' => [1]],

        // ---------- Viajes a otras zonas ----------
        ['id' => 'MAN28', 'tema' => 'Viajes a otras zonas', 'tipo' => 'multiple',
         'texto' => 'Antes de viajar a otra zona o provincia, ¿qué haces?',
         'opciones' => ['Planifico la ruta y reviso el clima', 'Aviso al supervisor mi salida y mi llegada', 'Descanso bien la noche anterior', 'Salgo sin revisar la unidad para ganar tiempo'], 'correcta' => [0, 1, 2]],
        ['id' => 'MAN29', 'tema' => 'Viajes a otras zonas', 'tipo' => 'caso',
         'texto' => 'En la carretera, un bus va lento delante de ti en una curva con línea continua. ¿Qué haces?',
         'opciones' => ['Espero detrás, con distancia, hasta un tramo recto donde se pueda adelantar', 'Lo adelanto rápido en la curva', 'Le toco la bocina hasta que se haga a un lado', 'Me pego a él para que acelere'], 'correcta' => [0]],
        ['id' => 'MAN30', 'tema' => 'Viajes a otras zonas', 'tipo' => 'unica',
         'texto' => 'Para retroceder la camioneta en una calle angosta, lo más seguro es:',
         'opciones' => ['Que el compañero baje y me guíe desde atrás', 'Retroceder rápido mirando el espejo', 'Tocar bocina y retroceder', 'Retroceder sin mirar porque el sensor avisa'], 'correcta' => [0]],
    ],
];
