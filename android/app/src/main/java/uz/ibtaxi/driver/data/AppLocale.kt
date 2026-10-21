package uz.ibtaxi.driver.data

import android.content.Context
import android.content.res.Configuration
import java.util.Locale

/**
 * Ilova tillari: uz — o'zbek (lotin), oz — o'zbek (kirill), ru, en. Server ham shu kodlarni ishlatadi.
 * Kirill satrlari res/values-b+uz+Cyrl da (android/tools/uz_cyrillic.py yasaydi).
 */
object AppLocale {
    val codes = listOf("uz", "oz", "ru", "en")

    /** Har til o'z tilida — haydovchi o'z tilini taniydi */
    val labels = mapOf("uz" to "O'zbekcha", "oz" to "Ўзбекча", "ru" to "Русский", "en" to "English")

    /** Qisqa — tanlash qatori bir qatorga sig'sin */
    val shortLabels = mapOf("uz" to "O'z", "oz" to "Ўз", "ru" to "Ru", "en" to "En")

    fun normalize(code: String?): String = code?.takeIf { it in codes } ?: "uz"

    fun javaLocale(code: String): Locale = when (normalize(code)) {
        "oz" -> Locale.forLanguageTag("uz-Cyrl")
        "ru" -> Locale.forLanguageTag("ru")
        "en" -> Locale.ENGLISH
        else -> Locale.forLanguageTag("uz")
    }

    /** Ovozli navigatsiya: TTS dvigatelida kirill o'zbekcha alohida emas — o'zbekcha ovoz */
    fun speechLocale(code: String): Locale = if (normalize(code) == "oz") Locale.forLanguageTag("uz") else javaLocale(code)

    /** Activity/Service konteksti tanlangan tilda (telefon tilidan qat'i nazar) */
    fun wrap(base: Context, code: String): Context {
        val locale = javaLocale(code)
        Locale.setDefault(locale)
        val config = Configuration(base.resources.configuration).apply { setLocale(locale) }
        return base.createConfigurationContext(config)
    }
}
