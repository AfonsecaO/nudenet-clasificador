<?php

namespace App\Services;

/**
 * Configuración de AWS Rekognition (Content Moderation).
 * Lee desde la sección "aws" de database/storage_engine.json.
 */
class AwsRekognitionConfig
{
    private const DEFAULT_REGION = 'us-east-1';
    private const DEFAULT_VERSION = 'latest';
    private const DEFAULT_MIN_CONFIDENCE = 50.0;

    /**
     * Access Key ID (opcional si se usan variables de entorno o instance profile).
     */
    public static function getKey(): string
    {
        $aws = StorageEngineConfig::getAwsConfig();
        return isset($aws['key']) ? trim((string) $aws['key']) : '';
    }

    /**
     * Secret Access Key (opcional; en blanco = no cambiar en UI).
     */
    public static function getSecret(): string
    {
        $aws = StorageEngineConfig::getAwsConfig();
        return isset($aws['secret']) ? (string) $aws['secret'] : '';
    }

    /**
     * Región AWS. Por defecto us-east-1.
     */
    public static function getRegion(): string
    {
        $aws = StorageEngineConfig::getAwsConfig();
        $region = isset($aws['region']) ? trim((string) $aws['region']) : '';
        return $region !== '' ? $region : self::DEFAULT_REGION;
    }

    /**
     * Versión del SDK (ej. "latest"). Para la API se usa RequestedModelVersion V7_0 en el código.
     */
    public static function getVersion(): string
    {
        $aws = StorageEngineConfig::getAwsConfig();
        $version = isset($aws['version']) ? trim((string) $aws['version']) : '';
        return $version !== '' ? $version : self::DEFAULT_VERSION;
    }

    /**
     * Confianza mínima (MinConfidence) enviada a Rekognition (0–100). Por defecto 50 según documentación AWS.
     */
    public static function getMinConfidence(): float
    {
        $aws = StorageEngineConfig::getAwsConfig();
        if (!isset($aws['min_confidence'])) {
            return self::DEFAULT_MIN_CONFIDENCE;
        }
        $v = (float) $aws['min_confidence'];
        return max(0.0, min(100.0, $v));
    }

    /**
     * Indica si hay credenciales configuradas (key presente) para usar el cliente Rekognition.
     */
    public static function isConfigured(): bool
    {
        return self::getKey() !== '';
    }
}
