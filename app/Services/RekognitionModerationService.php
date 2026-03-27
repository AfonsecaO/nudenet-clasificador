<?php

namespace App\Services;

use Aws\Rekognition\RekognitionClient;
use Aws\Exception\AwsException;

/**
 * Servicio que llama a AWS Rekognition DetectModerationLabels.
 * Usa AwsRekognitionConfig para credenciales y MinConfidence.
 */
class RekognitionModerationService
{
    /** Límite de Rekognition para image.bytes (5 MB) */
    private const MAX_IMAGE_BYTES = 5242880;

    private const REQUESTED_MODEL_VERSION = 'V7_0';
    private const MAX_RETRIES = 3;
    private const RETRY_DELAY_MS = 1000;

    /**
     * Detecta etiquetas de moderación en una imagen (ruta de archivo o bytes).
     * MinConfidence se toma de AwsRekognitionConfig::getMinConfidence() si no se pasa.
     *
     * @param string|resource $imagePathOrBytes Ruta al archivo .jpg/.png o string con bytes de la imagen
     * @param float|null $minConfidence 0-100, null = usar config global
     * @return array{ModerationLabels:array, ModerationModelVersion:string, ContentTypes:array}
     * @throws \Throwable Si el servicio falla (no se marca la imagen como analizada para poder reprocesar)
     */
    public function detectModerationLabels($imagePathOrBytes, ?float $minConfidence = null): array
    {
        $minConfidence = $minConfidence ?? AwsRekognitionConfig::getMinConfidence();
        $minConfidence = max(0.0, min(100.0, $minConfidence));

        $bytes = $this->imageToBytes($imagePathOrBytes);
        if ($bytes === null || $bytes === '') {
            throw new \InvalidArgumentException('No se pudo leer la imagen');
        }

        $client = $this->buildClient();
        $params = [
            'Image' => ['Bytes' => $bytes],
            'MinConfidence' => $minConfidence,
            'RequestedModelVersion' => self::REQUESTED_MODEL_VERSION,
        ];

        $lastException = null;
        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $result = $client->detectModerationLabels($params);
                return [
                    'ModerationLabels' => $result->get('ModerationLabels') ?? [],
                    'ModerationModelVersion' => $result->get('ModerationModelVersion') ?? '',
                    'ContentTypes' => $result->get('ContentTypes') ?? [],
                ];
            } catch (AwsException $e) {
                $lastException = $e;
                $code = $e->getAwsErrorCode() ?? '';
                if ($code === 'ThrottlingException' && $attempt < self::MAX_RETRIES) {
                    usleep(self::RETRY_DELAY_MS * 1000 * $attempt);
                    continue;
                }
                throw $e;
            }
        }
        if ($lastException) {
            throw $lastException;
        }
        throw new \RuntimeException('Error inesperado en Rekognition');
    }

    private function buildClient(): RekognitionClient
    {
        $config = [
            'region' => AwsRekognitionConfig::getRegion(),
            'version' => AwsRekognitionConfig::getVersion(),
        ];
        $key = AwsRekognitionConfig::getKey();
        $secret = AwsRekognitionConfig::getSecret();
        if ($key !== '' && $secret !== '') {
            $config['credentials'] = [
                'key' => $key,
                'secret' => $secret,
            ];
        }
        return new RekognitionClient($config);
    }

    /**
     * Convierte ruta o bytes a string de bytes de imagen (JPEG/PNG aceptados por Rekognition).
     * Si el tamaño supera MAX_IMAGE_BYTES (5 MB), reduce la imagen antes de enviar.
     */
    private function imageToBytes($imagePathOrBytes): ?string
    {
        $bytes = null;
        if (is_string($imagePathOrBytes)) {
            if (strlen($imagePathOrBytes) > 0 && strlen($imagePathOrBytes) < 4096 && is_file($imagePathOrBytes)) {
                $path = $imagePathOrBytes;
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if (in_array($ext, ['heic', 'heif'], true)) {
                    $jpgPath = HeicConverter::convertFileToJpg($path);
                    if ($jpgPath === null) {
                        return null;
                    }
                    $path = $jpgPath;
                }
                $bytes = @file_get_contents($path);
                if ($bytes === false) {
                    return null;
                }
            } else {
                $bytes = $imagePathOrBytes;
            }
        } elseif (is_resource($imagePathOrBytes)) {
            $bytes = stream_get_contents($imagePathOrBytes);
            if ($bytes === false) {
                return null;
            }
        }
        if ($bytes === null || $bytes === '') {
            return null;
        }
        if (strlen($bytes) > self::MAX_IMAGE_BYTES) {
            $reduced = ImageCompressor::shrinkToMaxBytes($bytes, self::MAX_IMAGE_BYTES);
            if ($reduced !== null) {
                return $reduced;
            }
            throw new \InvalidArgumentException(
                'Imagen demasiado grande (máx. ' . (self::MAX_IMAGE_BYTES / 1048576) . ' MB) y no se pudo reducir.'
            );
        }
        return $bytes;
    }
}
