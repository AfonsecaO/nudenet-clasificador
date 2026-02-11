<?php

namespace App\Services;

/**
 * Lista de labels oficiales (según documentación) + diccionario para UI.
 *
 * Nota: la app normaliza labels a MAYÚSCULAS y colapsa espacios.
 */
class DetectionLabels
{
    /**
     * Labels oficiales conocidos (NudeNet 3.4.x).
     * Fuente: documentación oficial del paquete `nudenet` (PyPI).
     *
     * Ver: https://pypi.org/project/nudenet/
     *
     * @return string[] labels normalizados (MAYÚSCULAS, con espacios)
     */
    public static function officialLabels(): array
    {
        $labels = [
            // all_labels (PyPI)
            'FEMALE_GENITALIA_COVERED',
            'FACE_FEMALE',
            'BUTTOCKS_EXPOSED',
            'FEMALE_BREAST_EXPOSED',
            'FEMALE_GENITALIA_EXPOSED',
            'MALE_BREAST_EXPOSED',
            'ANUS_EXPOSED',
            'FEET_EXPOSED',
            'BELLY_COVERED',
            'FEET_COVERED',
            'ARMPITS_COVERED',
            'ARMPITS_EXPOSED',
            'FACE_MALE',
            'BELLY_EXPOSED',
            'MALE_GENITALIA_EXPOSED',
            'ANUS_COVERED',
            'FEMALE_BREAST_COVERED',
            'BUTTOCKS_COVERED',
        ];

        $out = [];
        foreach ($labels as $l) {
            $k = self::normalizeLabel($l);
            if ($k !== '') $out[$k] = true;
        }
        $res = array_keys($out);
        sort($res, SORT_NATURAL | SORT_FLAG_CASE);
        return $res;
    }

    /**
     * Diccionario de visualización (ES). Si no existe, se usa el código.
     *
     * @return array<string,string> label_normalizado => texto
     */
    public static function dictionaryEs(): array
    {
        return [
            // PyPI labels
            'FEMALE_GENITALIA_COVERED' => 'Genitales femeninos (cubiertos)',
            'FACE_FEMALE' => 'Rostro femenino',
            'BUTTOCKS_EXPOSED' => 'Glúteos expuestos',
            'FEMALE_BREAST_EXPOSED' => 'Pecho femenino expuesto',
            'FEMALE_GENITALIA_EXPOSED' => 'Genitales femeninos expuestos',
            'MALE_BREAST_EXPOSED' => 'Pecho masculino expuesto',
            'ANUS_EXPOSED' => 'Ano expuesto',
            'FEET_EXPOSED' => 'Pies expuestos',
            'BELLY_COVERED' => 'Abdomen (cubierto)',
            'FEET_COVERED' => 'Pies (cubiertos)',
            'ARMPITS_COVERED' => 'Axilas (cubiertas)',
            'ARMPITS_EXPOSED' => 'Axilas expuestas',
            'FACE_MALE' => 'Rostro masculino',
            'BELLY_EXPOSED' => 'Abdomen expuesto',
            'MALE_GENITALIA_EXPOSED' => 'Genitales masculinos expuestos',
            'ANUS_COVERED' => 'Ano (cubierto)',
            'FEMALE_BREAST_COVERED' => 'Pecho femenino (cubierto)',
            'BUTTOCKS_COVERED' => 'Glúteos (cubiertos)',

            // Aliases comunes (modelos con espacios) -> mismo texto
            'EXPOSED ANUS' => 'Ano expuesto',
            'EXPOSED ARMPITS' => 'Axilas expuestas',
            'BELLY' => 'Abdomen',
            'EXPOSED BELLY' => 'Abdomen expuesto',
            'BUTTOCKS' => 'Glúteos',
            'EXPOSED BUTTOCKS' => 'Glúteos expuestos',
            'FEMALE FACE' => 'Rostro femenino',
            'MALE FACE' => 'Rostro masculino',
            'FEET' => 'Pies',
            'EXPOSED FEET' => 'Pies expuestos',
            'BREAST' => 'Pecho',
            'EXPOSED BREAST' => 'Pecho expuesto',
            'EXPOSED BREASTS' => 'Pechos expuestos',
            'VAGINA' => 'Vagina',
            'EXPOSED VAGINA' => 'Vagina expuesta',
            'EXPOSED PENIS' => 'Pene expuesto',
            'MALE BREAST' => 'Pecho masculino',
        ];
    }

    /**
     * Labels que se ignoran por defecto en workspaces nuevos (lista negra inicial).
     * Si el workspace ya tiene DETECT_IGNORED_LABELS en BD, se prioriza esa configuración.
     *
     * @return string[] códigos normalizados
     */
    public static function defaultIgnoredLabels(): array
    {
        return [
            'ARMPITS_COVERED',
            'BELLY_COVERED',
            'BELLY_EXPOSED',
            'FACE_FEMALE',
            'FACE_MALE',
            'FEET_COVERED',
            'FEET_EXPOSED',
            'MALE_BREAST_EXPOSED',
            'MALE_GENITALIA_EXPOSED',
        ];
    }

    public static function normalizeLabel(string $label): string
    {
        $label = strtoupper(trim($label));
        if ($label === '') return '';
        // Colapsar espacios y tabs
        $label = preg_replace('/\s+/', ' ', $label);
        return trim((string)$label);
    }
}

