<?php

namespace Database\Factories;

/**
 * Test va namuna ma'lumotlari uchun o'zbekcha nomlar (Faker'da o'zbek tili yo'q —
 * inglizcha "Gonzalomouth" kabi nomlar panelda chalg'itadi).
 */
class UzbekNames
{
    public const MALE = ['Abdulla', 'Akmal', 'Alisher', 'Anvar', 'Aziz', 'Bahodir', 'Bekzod', 'Bobur', 'Davron', 'Dilshod', 'Doniyor', 'Eldor', 'Farrux', 'Fayzulla', "G'ayrat", 'Husan', 'Ibrohim', 'Islom', 'Jahongir', 'Jamshid', 'Javohir', 'Komil', 'Lochin', 'Mansur', 'Murod', 'Nodir', 'Odil', 'Otabek', 'Qahramon', 'Rustam', 'Sanjar', 'Sardor', 'Sherzod', 'Shoxrux', 'Sobir', 'Temur', "To'lqin", 'Ulug\'bek', 'Umid', 'Xurshid', 'Yusuf', 'Zafar'];

    public const FEMALE = ['Barno', 'Dilnoza', 'Dildora', 'Feruza', 'Gulnora', 'Kamola', 'Lola', 'Madina', 'Malika', 'Munisa', 'Nargiza', 'Nigora', 'Nodira', 'Ozoda', 'Sevara', 'Shahnoza', 'Umida', 'Zarina', 'Zuhra'];

    public const SURNAMES = ['Abdullayev', 'Aliyev', 'Azimov', 'Boboyev', "Do'stov", 'Ergashev', "G'aniyev", 'Hasanov', 'Ibragimov', 'Ismoilov', 'Jo\'rayev', 'Karimov', 'Mahmudov', 'Mirzayev', 'Nazarov', 'Nurmatov', 'Olimov', 'Qodirov', 'Rahimov', 'Rasulov', 'Saidov', 'Salimov', 'Sobirov', 'Toshmatov', 'Umarov', 'Usmonov', 'Xolmatov', 'Yo\'ldoshev', 'Yusupov', 'Zokirov'];

    public const CITIES = ["Farg'ona", "Marg'ilon", "Qo'qon", 'Quvasoy', 'Rishton', 'Oltiariq', "Bag'dod", 'Beshariq', "Bo'stonliq", 'Dang\'ara', 'Furqat', "Qo'shtepa", 'Toshloq', "Uchko'prik", "O'zbekiston", 'Yozyovon', 'Sox', 'Andijon', 'Asaka', 'Xonobod', 'Namangan', 'Chust', 'Kosonsoy', 'Toshkent', 'Chirchiq', 'Olmaliq', 'Samarqand', "Kattaqo'rg'on", 'Buxoro', 'Navoiy', 'Qarshi', 'Termiz', 'Jizzax', 'Guliston', 'Urganch', 'Xiva', 'Nukus'];

    public const LANDMARKS = ['Markaziy bozor', 'Dehqon bozori', 'Avtovokzal', 'Temir yo\'l vokzali', 'Shahar shifoxonasi', 'Tug\'ruqxona', 'Madaniyat saroyi', 'Istirohat bog\'i', 'Markaziy masjid', 'Hokimiyat', 'Universitet', 'Kollej', 'Maktab', 'Bolalar bog\'chasi', 'Sport majmuasi', 'Stadion', 'To\'yxona', 'Choyxona', 'Savdo markazi', 'Aeroport', 'Pochta', 'Bank', 'Dorixona', 'Yoqilg\'i shoxobchasi'];

    public static function firstName(): string
    {
        return fake()->boolean(80) ? fake()->randomElement(self::MALE) : fake()->randomElement(self::FEMALE);
    }

    public static function fullName(): string
    {
        $first = fake()->boolean(80) ? fake()->randomElement(self::MALE) : null;
        $female = $first === null;
        $surname = fake()->randomElement(self::SURNAMES);

        return ($first ?? fake()->randomElement(self::FEMALE)).' '.($female ? $surname.'a' : $surname);
    }

    public static function city(): string
    {
        return fake()->randomElement(self::CITIES);
    }

    /** "Markaziy bozor 3" — bir xil nomli mo'ljallar ko'p bo'lishi mumkin */
    public static function landmark(): string
    {
        return fake()->randomElement(self::LANDMARKS).' '.fake()->numberBetween(1, 99);
    }
}
