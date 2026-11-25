<?php

declare(strict_types=1);

namespace Gfh\Seeds\Demo;

/** Synthetic customers: invented names, example.test addresses and phone numbers in the 555 range. */
final class DemoCustomerData
{
    private const FIRST = ['Ana', 'Bianca', 'Carmela', 'Dino', 'Emil', 'Faye', 'Gino', 'Hazel', 'Ian', 'Joy', 'Kiko', 'Lea', 'Migo', 'Nina', 'Oscar', 'Pia', 'Rey', 'Sofia', 'Tito', 'Uma', 'Vince', 'Wendy', 'Yna', 'Zandro'];

    private const LAST = ['Santos', 'Reyes', 'Cruz', 'Bautista', 'Ocampo', 'Garcia', 'Torres', 'Flores', 'Villanueva', 'Ramos', 'Aquino', 'Castillo', 'Navarro', 'Dizon', 'Salazar', 'Pascual', 'Lacson', 'Soriano', 'Valdez', 'Marquez', 'Padilla', 'Lim', 'Tan', 'Uy'];

    public const STREETS = ['Rizal St.', 'Lacson St.', 'Araneta Ave.', 'Burgos St.', 'Gonzaga St.', 'Locsin St.', 'Luzuriaga St.'];

    public const BARANGAYS = ['Mandalagan', 'Villamonte', 'Tangub', 'Alijis', 'Handumanan', 'Singcang', 'Bata'];

    public const CITIES = ['Bacolod City', 'Bacolod City', 'Talisay City', 'Silay City', 'Bago City'];

    /** @return list<array{full_name: string, email: string, phone: string, customer_type: string, tier: string}> */
    public static function customers(): array
    {
        $rows = [];
        foreach (self::FIRST as $i => $first) {
            $last = self::LAST[($i * 5 + 3) % count(self::LAST)];
            $rows[] = [
                'full_name' => $first . ' ' . $last,
                'email' => strtolower($first . '.' . $last) . '.' . sprintf('%02d', $i + 1) . '@example.test',
                'phone' => sprintf('+63 917 555 %04d', 200 + $i),
                'customer_type' => $i % 6 === 5 ? 'Cafe Partner' : 'Retail',
                'tier' => $i % 6 === 5 ? 'Gold' : 'Standard',
            ];
        }

        return $rows;
    }

    /** The email that marks the history seed as done. */
    public static function anchorEmail(): string
    {
        return self::customers()[0]['email'];
    }
}
