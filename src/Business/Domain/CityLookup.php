<?php

declare(strict_types=1);

namespace App\Business\Domain;

/**
 * A qué ciudad pertenece un punto del mapa.
 *
 * Es una interfaz y no una llamada suelta a Google porque lo que se quiere del
 * dominio es la ciudad, no el proveedor: quien la usa no tiene que saber si
 * detrás hay una API, una tabla o nada.
 */
interface CityLookup
{
    /**
     * La ciudad de esas coordenadas, o `null` si no se ha podido averiguar
     * —sin clave, sin red, o un punto en mitad del mar—.
     *
     * **No lanza.** Quien la llama está guardando un negocio o recorriendo una
     * lista de cuatrocientos, y que la ficha no se guarde porque Google no
     * contesta sería cambiar un dato de adorno por el trabajo de alguien.
     */
    public function cityAt(float $latitude, float $longitude): ?string;
}
