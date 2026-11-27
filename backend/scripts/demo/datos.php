<?php

declare(strict_types=1);

/*
 * Datos de demostración de scripts/seed_demo.php. Los correos usan el dominio reservado example.com (RFC 2606):
 * ningún correo que envíe la API (recuperación, inscripciones) llega a una persona real. Todos empiezan por
 * "demo." para que --limpiar los reconozca.
 *
 * `dias` = hace cuántos días se creó el registro (las fechas se calculan al sembrar).
 */

return [
    'usuarios' => [
        'superadmin' => [
            'correo' => 'demo.superadmin@example.com', 'rol' => 'superadministrador', 'activo' => true,
            'nombre' => 'Laura', 'apellido' => 'Rincón', 'programa_academico' => 'Ingeniería de Sistemas y Computación',
            'semestre' => null, 'telefono' => '+573001110001',
            'biografia' => 'Coordina el semillero y administra el sitio web.',
        ],
        'admin' => [
            'correo' => 'demo.admin@example.com', 'rol' => 'administrador', 'activo' => true,
            'nombre' => 'Andrés', 'apellido' => 'Patiño', 'programa_academico' => 'Ingeniería de Sistemas y Computación',
            'semestre' => null, 'telefono' => '+573001110002',
            'biografia' => 'Gestiona las cuentas de los integrantes y las inscripciones.',
        ],
        'docente' => [
            'correo' => 'demo.docente@example.com', 'rol' => 'docente_director', 'activo' => true,
            'nombre' => 'Claudia', 'apellido' => 'Ramírez', 'programa_academico' => 'Ingeniería de Sistemas y Computación',
            'semestre' => null, 'telefono' => null,
            'biografia' => 'Docente directora del semillero. Investiga en ingeniería de software y analítica de datos.',
        ],
        'lider' => [
            'correo' => 'demo.lider@example.com', 'rol' => 'estudiante_lider', 'activo' => true,
            'nombre' => 'Julián', 'apellido' => 'Moreno', 'programa_academico' => 'Ingeniería de Sistemas y Computación',
            'semestre' => 9, 'telefono' => '+573001110004',
            'biografia' => 'Líder de proyectos. Le interesan el IoT y los sistemas embebidos.',
        ],
        'estudiante1' => [
            'correo' => 'demo.valentina@example.com', 'rol' => 'estudiante', 'activo' => true,
            'nombre' => 'Valentina', 'apellido' => 'Cárdenas', 'programa_academico' => 'Ingeniería de Sistemas y Computación',
            'semestre' => 6, 'telefono' => null, 'biografia' => 'Desarrollo web y bases de datos.',
        ],
        'estudiante2' => [
            'correo' => 'demo.santiago@example.com', 'rol' => 'estudiante', 'activo' => true,
            'nombre' => 'Santiago', 'apellido' => 'Herrera', 'programa_academico' => 'Ingeniería Electrónica',
            'semestre' => 4, 'telefono' => null, 'biografia' => null,
        ],
        'estudiante3' => [
            'correo' => 'demo.camila@example.com', 'rol' => 'estudiante', 'activo' => true,
            'nombre' => 'Camila', 'apellido' => 'Rojas', 'programa_academico' => 'Ingeniería de Sistemas y Computación',
            'semestre' => 7, 'telefono' => null, 'biografia' => 'Ciencia de datos y visualización.',
        ],
        // Egresado: `estado = false` (deserción/egreso, RF-08). No inicia sesión ni sale en el listado público.
        'egresado' => [
            'correo' => 'demo.egresado@example.com', 'rol' => 'estudiante', 'activo' => false,
            'nombre' => 'Diego', 'apellido' => 'Suárez', 'programa_academico' => 'Ingeniería de Sistemas y Computación',
            'semestre' => 10, 'telefono' => null, 'biografia' => 'Egresado del semillero.',
        ],
    ],

    // Proyectos (/projects) y artículos y noticias (/articles). Público = visible y no en revisión (DEC-B31).
    'documentos' => [
        [
            'categoria' => 'proyecto', 'estado' => 'ejecucion', 'visible' => true, 'autor' => 'lider',
            'integrantes' => ['estudiante1', 'estudiante2'], 'dias' => 60,
            'titulo' => 'Plataforma de monitoreo de calidad del aire',
            'descripcion' => 'Red de sensores de bajo costo que mide material particulado en el campus y publica los datos en un tablero web.',
            'contenido' => "## Objetivo\n\nMedir PM2.5 y PM10 en cinco puntos del campus y mostrar los datos en tiempo casi real.\n\n## Avance\n\n- Prototipo del nodo de medición terminado.\n- Tablero web en desarrollo.\n- Pendiente: calibración con la estación de referencia.",
        ],
        [
            'categoria' => 'proyecto', 'estado' => 'ejecucion', 'visible' => true, 'autor' => 'docente',
            'integrantes' => ['lider', 'estudiante3'], 'dias' => 45,
            'titulo' => 'Asistente virtual para trámites académicos',
            'descripcion' => 'Chatbot que responde preguntas frecuentes sobre matrícula, homologaciones y calendario académico.',
            'contenido' => "## Alcance\n\nEl asistente responde preguntas frecuentes a partir de los reglamentos publicados.\n\n## Metodología\n\n1. Recolección de preguntas frecuentes.\n2. Base de conocimiento.\n3. Pruebas con estudiantes de primer semestre.",
        ],
        [
            'categoria' => 'proyecto', 'estado' => 'finalizado', 'visible' => true, 'autor' => 'estudiante1',
            'integrantes' => ['egresado', 'estudiante2'], 'dias' => 120,
            'titulo' => 'Sistema de inventario para laboratorios',
            'descripcion' => 'Aplicación web para el préstamo y control de equipos de los laboratorios de la escuela.',
            'contenido' => "## Resultado\n\nEl sistema está en uso en dos laboratorios. Redujo el tiempo de préstamo de equipos y dejó trazabilidad de cada préstamo.\n\n## Tecnologías\n\nPHP, MongoDB y React.",
        ],
        [
            'categoria' => 'proyecto', 'estado' => 'finalizado', 'visible' => false, 'autor' => 'estudiante2',
            'integrantes' => [], 'dias' => 150,
            'titulo' => 'Prototipo de aplicación de movilidad',
            'descripcion' => 'Prototipo para compartir viajes entre estudiantes. Oculto: falta revisar los datos personales que muestra.',
            'contenido' => 'Prototipo terminado. **Oculto al público** hasta revisar el tratamiento de datos personales.',
        ],
        [
            'categoria' => 'proyecto', 'estado' => 'en_revision', 'visible' => false, 'autor' => 'estudiante3',
            'integrantes' => ['estudiante1'], 'dias' => 5,
            'titulo' => 'Análisis de datos de deserción estudiantil',
            'descripcion' => 'Propuesta para identificar factores asociados a la deserción a partir de datos anonimizados.',
            'contenido' => "## Propuesta\n\nAnalizar datos anonimizados de cohortes anteriores para encontrar factores de riesgo.\n\n> Borrador en revisión por la docente directora.",
        ],
        [
            'categoria' => 'proyecto', 'estado' => 'en_revision', 'visible' => false, 'autor' => 'lider',
            'integrantes' => [], 'dias' => 2,
            'titulo' => 'Robot seguidor de línea educativo',
            'descripcion' => 'Kit de bajo costo para enseñar programación en colegios de la región.',
            'contenido' => 'Borrador del proyecto. Falta definir el presupuesto del kit.',
        ],
        [
            'categoria' => 'articulo', 'estado' => 'publicado', 'visible' => true, 'autor' => 'docente',
            'integrantes' => [], 'dias' => 30,
            'titulo' => 'Buenas prácticas de seguridad en APIs REST',
            'descripcion' => 'Autenticación, validación de entradas y manejo de errores en servicios web.',
            'contenido' => "## Validar todo lo que entra\n\nNunca confíes en los datos del cliente: valida tipos y longitudes, y rechaza objetos donde esperas texto.\n\n## Errores sin trazas\n\nEn producción, los errores deben responder un código estable y un mensaje claro, sin detalles internos.",
        ],
        [
            'categoria' => 'articulo', 'estado' => 'publicado', 'visible' => true, 'autor' => 'lider',
            'integrantes' => ['estudiante1'], 'dias' => 20,
            'titulo' => 'Introducción a MongoDB para proyectos estudiantiles',
            'descripcion' => 'Conceptos básicos de bases de datos documentales con ejemplos del semillero.',
            'contenido' => "## Documentos y colecciones\n\nMongoDB guarda documentos JSON agrupados en colecciones.\n\n## Índices\n\nUn índice único evita correos repetidos sin consultas adicionales.",
        ],
        [
            'categoria' => 'articulo', 'estado' => 'en_revision', 'visible' => false, 'autor' => 'estudiante1',
            'integrantes' => [], 'dias' => 3,
            'titulo' => 'Comparativa de frameworks de frontend',
            'descripcion' => 'Ventajas y desventajas de tres frameworks populares para proyectos pequeños.',
            'contenido' => 'Borrador. Falta la sección de rendimiento.',
        ],
        [
            'categoria' => 'noticia', 'estado' => 'publicado', 'visible' => true, 'autor' => 'docente',
            'integrantes' => ['lider', 'estudiante3'], 'dias' => 15,
            'titulo' => 'El semillero presenta sus proyectos en el encuentro regional de semilleros',
            'descripcion' => 'Dos proyectos del semillero fueron seleccionados para la muestra regional.',
            'contenido' => 'Los proyectos *Plataforma de monitoreo de calidad del aire* y *Asistente virtual para trámites académicos* se presentarán en la muestra regional de semilleros de investigación.',
        ],
        [
            'categoria' => 'noticia', 'estado' => 'publicado', 'visible' => true, 'autor' => 'admin',
            'integrantes' => [], 'dias' => 7,
            'titulo' => 'Abiertas las inscripciones para el próximo semestre',
            'descripcion' => 'Los estudiantes interesados pueden enviar su solicitud desde el sitio web.',
            'contenido' => 'Envía tu solicitud desde la página de inscripción con tu carta de motivación. El semillero revisará las solicitudes y te responderá por correo.',
        ],
        [
            'categoria' => 'noticia', 'estado' => 'en_revision', 'visible' => false, 'autor' => 'estudiante2',
            'integrantes' => [], 'dias' => 1,
            'titulo' => 'Taller de introducción a Git',
            'descripcion' => 'Taller práctico de control de versiones para los nuevos integrantes.',
            'contenido' => 'Borrador del anuncio. Falta confirmar la fecha y el salón.',
        ],
    ],

    // `usuario` null = visitante sin sesión. Las de visitantes se identifican por el comentario al limpiar.
    'resenas' => [
        ['usuario' => 'estudiante1', 'nombre_visible' => null, 'calificacion' => 5, 'estado' => 'visible', 'dias' => 40,
            'comentario' => 'El semillero me ayudó a aplicar lo que vemos en clase en proyectos reales.'],
        ['usuario' => 'estudiante2', 'nombre_visible' => null, 'calificacion' => 4, 'estado' => 'visible', 'dias' => 35,
            'comentario' => 'Buen ambiente de trabajo y mucho aprendizaje en electrónica.'],
        ['usuario' => 'lider', 'nombre_visible' => null, 'calificacion' => 5, 'estado' => 'visible', 'dias' => 28,
            'comentario' => 'Liderar un proyecto aquí ha sido la mejor experiencia de la carrera.'],
        ['usuario' => null, 'nombre_visible' => 'Mariana (visitante)', 'calificacion' => 5, 'estado' => 'visible', 'dias' => 25,
            'comentario' => 'Vi la presentación del monitoreo de calidad del aire y me pareció excelente.'],
        ['usuario' => null, 'nombre_visible' => 'Profesor invitado', 'calificacion' => 4, 'estado' => 'visible', 'dias' => 21,
            'comentario' => 'Proyectos bien documentados y con impacto en la universidad.'],
        ['usuario' => 'estudiante3', 'nombre_visible' => null, 'calificacion' => null, 'estado' => 'visible', 'dias' => 18,
            'comentario' => 'Recomiendo el semillero a quien quiera iniciarse en investigación.'],
        ['usuario' => null, 'nombre_visible' => 'Egresado UPTC', 'calificacion' => 3, 'estado' => 'visible', 'dias' => 12,
            'comentario' => 'Buena iniciativa; sería bueno publicar más artículos.'],
        ['usuario' => null, 'nombre_visible' => 'Anónimo', 'calificacion' => 1, 'estado' => 'oculta', 'dias' => 6,
            'comentario' => 'Reseña de prueba que quedó oculta por moderación.'],
        ['usuario' => null, 'nombre_visible' => 'Carlos', 'calificacion' => 5, 'estado' => 'oculta', 'dias' => 3,
            'comentario' => '¿Cómo me inscribo? Quiero entrar al semillero el próximo semestre.'],
        ['usuario' => null, 'nombre_visible' => 'Visitante', 'calificacion' => 4, 'estado' => 'oculta', 'dias' => 1,
            'comentario' => 'Reseña nueva de un visitante, pendiente de revisión.'],
    ],

    'mensajes' => [
        ['nombre' => 'Sofía Gómez', 'correo' => 'demo.sofia@example.com', 'asunto' => 'Información sobre el semillero',
            'mensaje' => "Hola, quisiera saber qué requisitos hay para unirme al semillero.\nGracias.", 'estado' => 'leido', 'dias' => 20],
        ['nombre' => 'Empresa de software', 'correo' => 'demo.empresa@example.com', 'asunto' => 'Propuesta de colaboración',
            'mensaje' => 'Nos interesa apoyar un proyecto del semillero con mentorías. ¿Con quién podemos hablar?', 'estado' => 'leido', 'dias' => 14],
        ['nombre' => 'Pedro Castro', 'correo' => 'demo.pedro@example.com', 'asunto' => 'Charla para colegios',
            'mensaje' => 'Soy docente de un colegio y quisiera invitar al semillero a dar una charla sobre robótica.', 'estado' => 'no_leido', 'dias' => 4],
        ['nombre' => 'Ana María López', 'correo' => 'demo.anamaria@example.com', 'asunto' => 'Uso de datos del monitoreo del aire',
            'mensaje' => '¿Los datos del proyecto de calidad del aire están disponibles para un trabajo de grado?', 'estado' => 'no_leido', 'dias' => 2],
        ['nombre' => 'Luis Pérez', 'correo' => 'demo.luis@example.com', 'asunto' => 'Error en el formulario',
            'mensaje' => 'Al enviar la inscripción no me llegó el correo de confirmación.', 'estado' => 'no_leido', 'dias' => 0],
    ],

    // Inscripciones pendientes. Cada documento es un PDF generado por el script y subido privado (DEC-B50).
    'inscripciones' => [
        [
            'nombre_completo' => 'María Fernanda Ortiz', 'correo' => 'demo.aspirante1@example.com', 'telefono' => '+573002220001',
            'programa_academico' => 'Ingeniería de Sistemas y Computación', 'semestre' => 3, 'dias' => 4,
            'carta_motivacion' => 'Quiero unirme al semillero porque me interesa la investigación aplicada. He participado en '
                . 'una maratón de programación y me gustaría aportar en proyectos de analítica de datos.',
            'documentos' => ['carta_motivacion.pdf', 'certificado_notas.pdf'],
        ],
        [
            'nombre_completo' => 'Kevin Alejandro Torres', 'correo' => 'demo.aspirante2@example.com', 'telefono' => '+573002220002',
            'programa_academico' => 'Ingeniería Electrónica', 'semestre' => 5, 'dias' => 2,
            'carta_motivacion' => 'Me apasionan los sistemas embebidos y quiero aprender a llevar un prototipo hasta un '
                . 'producto. Puedo dedicar diez horas semanales a los proyectos del semillero.',
            'documentos' => ['hoja_de_vida.pdf'],
        ],
        [
            'nombre_completo' => 'Daniela Sofía Niño', 'correo' => 'demo.aspirante3@example.com', 'telefono' => '+573002220003',
            'programa_academico' => 'Ingeniería de Sistemas y Computación', 'semestre' => 2, 'dias' => 0,
            'carta_motivacion' => 'Estoy empezando la carrera y quiero formarme en investigación desde temprano. Me interesa '
                . 'el desarrollo web y me gustaría colaborar en el sitio del semillero.',
            'documentos' => ['carta_motivacion.pdf'],
        ],
    ],

    // Multimedia: imágenes generadas por el script (degradado + círculos) y subidas públicas a siiis_test/demo.
    'imagenes' => [
        ['seccion' => 'slider_principal', 'orden' => 1, 'desde' => [18, 52, 86], 'hasta' => [32, 140, 160],
            'descripcion' => 'Demo: integrantes del semillero trabajando en el laboratorio'],
        ['seccion' => 'slider_principal', 'orden' => 2, 'desde' => [90, 24, 60], 'hasta' => [240, 140, 60],
            'descripcion' => 'Demo: presentación de proyectos en el encuentro regional'],
        ['seccion' => 'slider_principal', 'orden' => 3, 'desde' => [20, 80, 40], 'hasta' => [150, 200, 90],
            'descripcion' => 'Demo: sensores del proyecto de calidad del aire en el campus'],
        ['seccion' => 'galeria', 'orden' => 1, 'desde' => [40, 40, 90], 'hasta' => [120, 90, 200],
            'descripcion' => 'Demo: taller de introducción a la programación'],
        ['seccion' => 'galeria', 'orden' => 2, 'desde' => [100, 60, 20], 'hasta' => [230, 200, 80],
            'descripcion' => 'Demo: prototipo del robot seguidor de línea'],
        ['seccion' => 'galeria', 'orden' => 3, 'desde' => [10, 90, 100], 'hasta' => [140, 220, 210],
            'descripcion' => 'Demo: reunión semanal del semillero'],
    ],

    // Video: Cloudinary lo copia desde el cloud público "demo" a siiis_test/demo (no se genera en local).
    'video' => [
        'origen' => 'https://res.cloudinary.com/demo/video/upload/dog.mp4',
        'seccion' => 'video_presentacion', 'orden' => 1,
        'descripcion' => 'Demo: video de presentación del semillero',
    ],
];
