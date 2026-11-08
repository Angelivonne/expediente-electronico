<?php
/**
 * Configuración del motor de voz a texto (STT) para ExpedienteFEMECI.
 * Modelo: Parakeet TDT 0.6B v3 (NVIDIA) - 485 MB, español, código abierto CC-BY-4.0.
 *
 * Archivo del modelo: models/stt/parakeet-tdt-0.6b-v3-Q4_K_M.gguf
 * Descarga: https://huggingface.co/handy-computer/parakeet-tdt-0.6b-v3-gguf/resolve/main/parakeet-tdt-0.6b-v3-Q4_K_M.gguf
 */

// Ruta al modelo descargado (ajusta si lo guardas en otro lugar)
define('STT_MODELO_PATH', __DIR__ . '/../models/stt/parakeet-tdt-0.6b-v3-Q4_K_M.gguf');

// Configuración de audio
define('STT_MUESTRAS_POR_SEGUNDO', 16000);
define('STT_CANALES', 1); // mono
define('STT_TIEMPO_MAXIMO_SEGUNDOS', 300); // 5 minutos máximo por dictado

// Idioma
define('STT_IDIOMA', 'es'); // español
