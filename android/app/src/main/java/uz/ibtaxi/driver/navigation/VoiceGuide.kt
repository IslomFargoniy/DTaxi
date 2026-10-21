package uz.ibtaxi.driver.navigation

import android.content.Context
import android.media.AudioAttributes
import android.speech.tts.TextToSpeech
import uz.ibtaxi.driver.data.AppLocale

/**
 * Ovozli ko'rsatmalar: telefonning TTS dvigateli, internet kerak emas.
 * Google TTS'da o'zbekcha ovoz yo'q — shunda ko'rsatmalar ruscha (u ham bo'lmasa inglizcha) aytiladi,
 * aks holda o'zbek tilidagi haydovchi navigatsiyani umuman eshitmasdi. Banner esa ilova tilida qoladi.
 */
class VoiceGuide(context: Context, private val locale: String) {
    /** Ko'rsatma qaysi tilda aytiladi (Instructions.text uchun); null — dvigatelda mos til yo'q */
    @Volatile var lang: String? = null
        private set

    private val tts: TextToSpeech = TextToSpeech(context.applicationContext) { status ->
        if (status == TextToSpeech.SUCCESS) lang = pickLanguage()
    }

    init {
        tts.setAudioAttributes(
            AudioAttributes.Builder()
                .setUsage(AudioAttributes.USAGE_ASSISTANCE_NAVIGATION_GUIDANCE)
                .setContentType(AudioAttributes.CONTENT_TYPE_SPEECH)
                .build(),
        )
    }

    val available: Boolean get() = lang != null

    private fun pickLanguage(): String? {
        val wanted = AppLocale.normalize(locale)
        val order = (listOf(wanted) + listOf("ru", "en")).distinct()
        return order.firstOrNull { code ->
            val result = tts.setLanguage(AppLocale.speechLocale(code))
            result != TextToSpeech.LANG_MISSING_DATA && result != TextToSpeech.LANG_NOT_SUPPORTED
        }
    }

    /** Ko'rsatma matni dvigatel tilida */
    fun say(text: (String) -> String) {
        val code = lang ?: return
        tts.speak(text(code), TextToSpeech.QUEUE_FLUSH, null, "nav")
    }

    fun shutdown() = tts.shutdown()
}
