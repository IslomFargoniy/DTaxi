package uz.dtaxi.core.data

import java.util.Locale

/**
 * Telefon, summa va davlat raqami ko'rinishlari — panel (resources/js/lib/format.ts)
 * va server (Client::normalizePhone, Vehicle::formatPlate) bilan bir xil qoidalar.
 */
object Formats {
    /** "+998901234567" → "+998 90 123 45 67", "+79123456789" → "+7 912 345 67 89"; boshqa qiymatlar o'zgarishsiz */
    fun formatPhone(value: String?): String = Phones.format(value)

    /** 25000 → "25 000" (qurilma tilidan qat'i nazar bir xil) */
    fun formatMoney(value: Long?): String = value?.let { String.format(Locale.ROOT, "%,d", it).replace(',', ' ') } ?: "—"

    /** "01A123BC" → "01 A 123 BC", "01123ABC" → "01 123 ABC"; boshqa format — o'zgarishsiz */
    fun formatPlate(value: String?): String {
        if (value.isNullOrBlank()) return ""
        val compact = value.uppercase(Locale.ROOT).filter { it in '0'..'9' || it in 'A'..'Z' }
        Regex("^(\\d{2})([A-Z])(\\d{3})([A-Z]{2})$").find(compact)?.let { return it.groupValues.drop(1).joinToString(" ") }
        Regex("^(\\d{2})(\\d{3})([A-Z]{3})$").find(compact)?.let { return it.groupValues.drop(1).joinToString(" ") }
        return value
    }
}
