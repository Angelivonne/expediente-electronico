<?php

// Agrega cada publicación al inicio con un id nuevo para anunciarla en la siguiente sesión.
return [
    [
        'id' => '2026-11-expediente-clinico',
        'periodo' => 'NOVIEMBRE 2026',
        'acento' => '#F4C7C3',
        'titulo' => 'Registra consultas, no papeles',
        'descripcion' => 'Un sistema de expedientes clínicos con inteligencia artificial, gratuito y libre, pensado para clínicas pequeñas y medianas.',
        'pestanas' => [
            [
                'id' => 'nov',
                'etiqueta' => 'Novedades',
                'elementos' => [
                    ['tipo' => 'Nuevo', 'clase' => 'rosa', 'forma' => 1, 'giro' => '-2deg', 'titulo' => 'Dictado por voz con inteligencia artificial', 'descripcion' => 'Graba la consulta hablando y la nota queda registrada más rápido.'],
                    ['tipo' => 'Nuevo', 'clase' => 'rosa', 'forma' => 2, 'giro' => '1.5deg', 'titulo' => 'Módulos listos para usarse', 'descripcion' => 'Consultas, notas médicas y roles de administrador y médico ya funcionan.'],
                    ['tipo' => 'Mejora', 'clase' => 'azul', 'forma' => 3, 'giro' => '-1deg', 'titulo' => 'Diseño y eficiencia en mejora continua', 'descripcion' => 'Una interfaz minimalista y más ágil, pensada también para el celular.'],
                    ['tipo' => 'Visita', 'clase' => 'amarillo', 'forma' => 4, 'giro' => '2deg', 'titulo' => 'Presentado en una clínica de Matamoros', 'descripcion' => 'Mostramos cómo se usa el sistema al personal de la clínica el 3 de noviembre.'],
                    ['tipo' => 'En camino', 'clase' => 'gris', 'forma' => 1, 'giro' => '-2deg', 'titulo' => 'Importar y exportar tu base de datos', 'descripcion' => 'El sistema se adaptará al formato que ya tengas, sin importar el tamaño.'],
                ],
            ],
            [
                'id' => 'fun',
                'etiqueta' => 'Funciones',
                'elementos' => [
                    ['tipo' => 'Consultas', 'clase' => 'rosa', 'forma' => 2, 'giro' => '1.5deg', 'titulo' => 'Expediente de consulta', 'descripcion' => 'Motivo, notas del médico y datos del paciente en un solo lugar.'],
                    ['tipo' => 'Voz e IA', 'clase' => 'azul', 'forma' => 3, 'giro' => '-1deg', 'titulo' => 'Dictado por voz', 'descripcion' => 'Registra notas hablando, siempre bajo el criterio del médico.'],
                    ['tipo' => 'Accesos', 'clase' => 'amarillo', 'forma' => 4, 'giro' => '2deg', 'titulo' => 'Acceso por perfil', 'descripcion' => 'Administrador y médico, con ingreso por código de invitación.'],
                    ['tipo' => 'Datos', 'clase' => 'gris', 'forma' => 1, 'giro' => '-2deg', 'titulo' => 'Importar y exportar', 'descripcion' => 'Se adapta al formato de datos que ya tiene tu clínica.'],
                ],
            ],
            [
                'id' => 'seg',
                'etiqueta' => 'Seguridad',
                'elementos' => [
                    ['tipo' => 'Capa 1', 'clase' => 'rosa', 'forma' => 2, 'giro' => '1.5deg', 'titulo' => 'Registro verificado', 'descripcion' => 'Cuenta con correo y un código de verificación para iniciar sesión.'],
                    ['tipo' => 'Capa 2', 'clase' => 'azul', 'forma' => 3, 'giro' => '-1deg', 'titulo' => 'Ingreso por invitación', 'descripcion' => 'Solo los administradores dan códigos de invitación para entrar a una institución.'],
                    ['tipo' => 'Capa 3', 'clase' => 'amarillo', 'forma' => 4, 'giro' => '2deg', 'titulo' => 'Base de datos protegida', 'descripcion' => 'La información se protege con hash.'],
                ],
            ],
            [
                'id' => 'cli',
                'etiqueta' => 'Para clínicas',
                'elementos' => [
                    ['tipo' => 'Costo', 'clase' => 'rosa', 'forma' => 1, 'giro' => '-2deg', 'titulo' => 'Gratis y libre', 'descripcion' => 'No cobramos por el servicio ni hay mensualidades.'],
                    ['tipo' => 'Uso', 'clase' => 'azul', 'forma' => 2, 'giro' => '1.5deg', 'titulo' => 'Fácil de usar en celular', 'descripcion' => 'Cada clínica usa su celular, tableta o computadora.'],
                    ['tipo' => 'Normativa', 'clase' => 'amarillo', 'forma' => 3, 'giro' => '-1deg', 'titulo' => 'Referencia: NOM-004 y NOM-024', 'descripcion' => 'Diseñado tomando como base las normas del expediente clínico.'],
                    ['tipo' => 'Inicio', 'clase' => 'gris', 'forma' => 4, 'giro' => '2deg', 'titulo' => 'Empezamos en Coahuila', 'descripcion' => 'Pensado para clínicas pequeñas y medianas, con visión de crecer.'],
                ],
            ],
        ],
    ],
];
