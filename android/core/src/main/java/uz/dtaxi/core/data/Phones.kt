package uz.dtaxi.core.data

import android.content.Context
import android.telephony.TelephonyManager
import com.google.i18n.phonenumbers.NumberParseException
import com.google.i18n.phonenumbers.PhoneNumberUtil
import java.util.Locale

/**
 * Xalqaro telefon raqamlari (qaror #74) — panel (resources/js/lib/phone.ts) va server (Client::normalizePhone)
 * bilan bir xil: saqlash E.164, davlat tanlanadi, milliy qism shu davlat formatida yoziladi.
 */
object Phones {
    private val util: PhoneNumberUtil by lazy { PhoneNumberUtil.getInstance() }

    /** Ro'yxat boshidagi qo'shni davlatlar */
    val nearby = listOf("UZ", "KZ", "KG", "TJ", "TM", "RU", "AZ", "TR")

    data class Country(val iso: String, val dial: Int, val flag: String)

    val countries: List<Country> by lazy {
        util.supportedRegions.map { Country(it, util.getCountryCodeForRegion(it), flag(it)) }
    }

    fun dial(iso: String): Int = util.getCountryCodeForRegion(iso)

    /** "UZ" → 🇺🇿 (Android bayroq emojilarini chizadi) */
    fun flag(iso: String): String =
        iso.uppercase(Locale.ROOT).map { String(Character.toChars(0x1F1E6 + (it - 'A'))) }.joinToString("")

    /** Davlat nomi ilova tilida */
    fun name(iso: String, locale: Locale): String = Locale("", iso).getDisplayCountry(locale).ifBlank { iso }

    private const val PREFS = "dtaxi_phone"
    private const val KEY = "country"

    /** Yangi raqam uchun davlat: oxirgi tanlangan → SIM/tarmoq davlati → firma davlati */
    fun defaultCountry(context: Context, firm: String): String {
        val saved = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getString(KEY, null)
        if (saved != null && saved in util.supportedRegions) return saved
        val telephony = context.getSystemService(TelephonyManager::class.java)
        val detected = listOfNotNull(telephony?.networkCountryIso, telephony?.simCountryIso)
            .map { it.uppercase(Locale.ROOT) }
            .firstOrNull { it in util.supportedRegions }
        return detected ?: firm.uppercase(Locale.ROOT).takeIf { it in util.supportedRegions } ?: "UZ"
    }

    fun remember(context: Context, iso: String) {
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit().putString(KEY, iso).apply()
    }

    /**
     * Maydonga yozilgan matn → (davlat, milliy raqamlar). To'liq raqam ("+7 912 …", "00998…") joylansa yoki
     * avtoto'ldirilsa davlat ham raqamdan olinadi; ortiqcha raqam qo'shilmaydi.
     */
    fun input(iso: String, previous: String, raw: String): Pair<String, String> {
        val trimmed = raw.trim()
        val digits = raw.filter(Char::isDigit)
        val international = trimmed.startsWith("+") || trimmed.startsWith("00") ||
            (digits.startsWith(dial(iso).toString()) && tooLong(iso, digits))
        if (international && digits.isNotEmpty()) {
            split("+" + digits.removePrefix("00"), iso)?.let { return it }
        }
        var next = digits
        // Eski "8 90 …" yozuvi (O'zbekiston)
        if (iso == "UZ" && next.length == 10 && next.startsWith("8")) next = next.drop(1)
        if (tooLong(iso, next) && next.length > previous.length) return iso to previous
        return iso to next
    }

    /** "+998901234567" → ("UZ", "901234567"); telefon bo'lmasa — null */
    fun split(e164: String, fallback: String): Pair<String, String>? = try {
        val number = util.parse(e164, fallback)
        val region = util.getRegionCodeForNumber(number)
            ?: util.getRegionCodeForCountryCode(number.countryCode).takeIf { it != "ZZ" }
            ?: fallback
        region to number.nationalNumber.toString()
    } catch (_: NumberParseException) {
        null
    }

    fun tooLong(iso: String, national: String): Boolean = national.isNotEmpty() && try {
        util.isPossibleNumberWithReason(util.parse(national, iso)) == PhoneNumberUtil.ValidationResult.TOO_LONG
    } catch (_: NumberParseException) {
        national.length > 15
    }

    fun isValid(iso: String, national: String): Boolean = national.isNotEmpty() && try {
        util.isValidNumber(util.parse(national, iso))
    } catch (_: NumberParseException) {
        false
    }

    fun e164(iso: String, national: String): String = if (national.isEmpty()) "" else "+${dial(iso)}$national"

    /** Yozish davomida: "90123" → "90 123" (shu davlat formatida) */
    fun formatNational(iso: String, national: String): String {
        if (national.isEmpty()) return ""
        val formatter = util.getAsYouTypeFormatter(iso)
        var out = ""
        national.forEach { out = formatter.inputDigit(it) }
        return out
    }

    /** Namunaviy raqam — placeholder uchun */
    fun example(iso: String): String = util.getExampleNumberForType(iso, PhoneNumberUtil.PhoneNumberType.MOBILE)
        ?.let { util.format(it, PhoneNumberUtil.PhoneNumberFormat.NATIONAL) }
        .orEmpty()

    /** Ko'rsatish uchun: "+998901234567" → "+998 90 123 45 67"; telefon bo'lmasa — o'zgarishsiz */
    fun format(value: String?): String {
        if (value.isNullOrBlank()) return ""
        val digits = value.filter(Char::isDigit)
        // Eski yozuvlar: "+" siz yoki faqat milliy 9 raqam (O'zbekiston)
        val candidate = when {
            value.trim().startsWith("+") -> value
            digits.length == 9 -> "+998$digits"
            else -> "+$digits"
        }
        return try {
            val number = util.parse(candidate, "ZZ")
            if (util.isPossibleNumber(number)) {
                util.format(number, PhoneNumberUtil.PhoneNumberFormat.INTERNATIONAL).replace('-', ' ')
            } else {
                value
            }
        } catch (_: NumberParseException) {
            value
        }
    }
}
