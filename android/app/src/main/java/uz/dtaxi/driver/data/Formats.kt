package uz.dtaxi.driver.data

import java.util.Locale

/**
 * Telefon, summa va davlat raqami ko'rinishlari — panel (resources/js/lib/format.ts)
 * va server (Client::normalizePhone, Vehicle::formatPlate) bilan bir xil qoidalar.
 */
object Formats {
    /** O'zbekiston raqamining milliy qismi (operator kodi + 7 raqam) */
    const val PHONE_NATIONAL_LENGTH = 9

    /** "+998 90 123-45-67", "998901234567", "8 90 123 45 67" → "901234567" */
    fun phoneNational(raw: String): String {
        var digits = raw.filter(Char::isDigit)
        if (digits.startsWith("00")) digits = digits.drop(2)
        if (digits.startsWith("998") && digits.length > PHONE_NATIONAL_LENGTH) {
            digits = digits.drop(3)
        } else if (digits.startsWith("8") && digits.length == PHONE_NATIONAL_LENGTH + 1) {
            // Eski "8 90 ..." yozuvi
            digits = digits.drop(1)
        }
        return digits.take(PHONE_NATIONAL_LENGTH)
    }

    /**
     * Kiritish maydoni uchun: yozilgan raqamlar (to'liq raqam joylansa yoki
     * avtoto'ldirilsa — milliy qismi) — ko'pi bilan 9 ta raqam.
     */
    fun phoneInput(raw: String): String {
        val digits = raw.filter(Char::isDigit)
        return if (raw.contains('+') || digits.length > PHONE_NATIONAL_LENGTH + 1) {
            phoneNational(raw)
        } else {
            digits.take(PHONE_NATIONAL_LENGTH)
        }
    }

    /** "901234567" → "90 123 45 67" (yozish davomida qisman ham) */
    fun formatPhoneNational(national: String): String =
        listOf(0..1, 2..4, 5..6, 7..8)
            .mapNotNull { range -> national.substring(minOf(range.first, national.length), minOf(range.last + 1, national.length)).ifEmpty { null } }
            .joinToString(" ")

    /** "+998901234567" → "+998 90 123 45 67"; boshqa raqamlar o'zgarishsiz */
    fun formatPhone(value: String?): String {
        if (value.isNullOrBlank()) return ""
        val digits = value.filter(Char::isDigit)
        val uz = (digits.length == 12 && digits.startsWith("998")) || digits.length == PHONE_NATIONAL_LENGTH
        return if (uz) "+998 " + formatPhoneNational(digits.takeLast(PHONE_NATIONAL_LENGTH)) else value
    }

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
