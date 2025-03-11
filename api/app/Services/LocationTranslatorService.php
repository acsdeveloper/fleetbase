<?php

namespace App\Services;

use Illuminate\Support\Facades\Lang;

class LocationTranslatorService
{
    /**
     * Translate a city name to the specified locale
     *
     * @param string $cityName
     * @param string|null $locale
     * @return string
     */
    public function translateCity($key, $locale)
    {
        $locale = $locale ?? app()->getLocale();
        if (!$key || !$locale) {
            return $key;
        }
         $translated = Lang::has("places.{$key}") 
         ? trans("places.{$key}") 
         : $key;
     
        // Restore original locale
        app()->setLocale($locale);
        
        return $translated;
    }
}