<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Tamaño de letra de la app: tres opciones y la del medio por defecto.
 *
 * En porcentaje y no en px: un px en la raíz pisaría el tamaño que la persona
 * ya configuró en su navegador; con % las dos preferencias se multiplican.
 * Tailwind mide en rem, así que escala la letra y el espaciado (no los
 * breakpoints, que van en px contra el viewport).
 */
enum TamanioTexto: string
{
    /**
     * El valor por defecto, como constante.
     *
     * Duplica a `porDefecto()` porque un inicializador de propiedad —el
     * `$attributes` de User— solo admite expresiones constantes.
     */
    public const string PORDEFECTO = 'normal';

    case Pequeno = 'pequeno';
    case Normal = 'normal';
    case Grande = 'grande';

    /**
     * El que se usa cuando nadie eligió nada.
     */
    public static function porDefecto(): self
    {
        return self::from(self::PORDEFECTO);
    }

    /**
     * Devuelve el caso, o el de por defecto si el valor no es ninguno.
     *
     * Una cookie la escribe el cliente y puede traer cualquier cosa; un valor
     * desconocido tiene que caer en el default y no romper la página.
     */
    public static function desde(?string $valor): self
    {
        return self::tryFrom((string) $valor) ?? self::porDefecto();
    }

    /**
     * Porcentaje que se aplica al `font-size` de `:root`.
     */
    public function porcentaje(): string
    {
        return match ($this) {
            self::Pequeno => '87.5%',
            self::Normal => '100%',
            self::Grande => '112.5%',
        };
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Pequeno => 'Pequeño',
            self::Normal => 'Normal',
            self::Grande => 'Grande',
        };
    }

    public function descripcion(): string
    {
        return match ($this) {
            self::Pequeno => 'Más chico: entran más cosas en pantalla.',
            self::Normal => 'El tamaño habitual. Es el que viene puesto.',
            self::Grande => 'Más grande y fácil de leer. Entran menos cosas.',
        };
    }

    /**
     * El porcentaje como número, para que el cliente pueda calcular escalas.
     *
     * La pantalla de Apariencia muestra cada opción **a su tamaño real**, y
     * para eso necesita la proporción entre la opción y la que está vigente.
     */
    public function escala(): float
    {
        return (float) rtrim($this->porcentaje(), '%');
    }

    /**
     * Para el selector de Apariencia, en orden de menor a mayor.
     *
     * @return list<array{valor: string, etiqueta: string, descripcion: string, escala: float}>
     */
    public static function opciones(): array
    {
        return array_map(static fn (self $caso): array => [
            'valor' => $caso->value,
            'etiqueta' => $caso->etiqueta(),
            'descripcion' => $caso->descripcion(),
            'escala' => $caso->escala(),
        ], self::cases());
    }
}
