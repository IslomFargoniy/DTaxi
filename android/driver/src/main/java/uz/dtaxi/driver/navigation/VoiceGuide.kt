package uz.dtaxi.driver.navigation

import android.content.Context
import android.media.AudioAttributes
import android.speech.tts.TextToSpeech
import android.speech.tts.Voice
import uz.dtaxi.core.data.AppLocale

/**
 * Ovozli ko'rsatmalar: telefonning TTS dvigateli, internet kerak emas.
 * Google TTS'da o'zbekcha ovoz yo'q — shunda ko'rsatmalar ruscha (u ham bo'lmasa inglizcha) aytiladi,
 * aks holda o'zbek tilidagi haydovchi navigatsiyani umuman eshitmasdi. Banner esa ilova tilida qoladi.
 */
class VoiceGuide(context: Context, private val locale: String, private val voiceName: String?) {
    /** Ko'rsatma qaysi tilda aytiladi (Instructions.text uchun); null — dvigatelda mos til yo'q */
    @Volatile var lang: String? = null
        private set

    private val tts: TextToSpeech = TextToSpeech(context.applicationContext) { status ->
        if (status == TextToSpeech.SUCCESS) {
            lang = pickLanguage(tts, locale)
            lang?.let { code -> voicesFor(tts, code).firstOrNull { it.name == voiceName }?.let { tts.voice = it } }
        }
    }

    init {
        tts.setAudioAttributes(navigationAudio)
    }

    val available: Boolean get() = lang != null

    /** Ko'rsatma matni dvigatel tilida */
    fun say(text: (String) -> String) {
        val code = lang ?: return
        tts.speak(text(code), TextToSpeech.QUEUE_FLUSH, null, "nav")
    }

    fun shutdown() = tts.shutdown()

    companion object {
        val navigationAudio: AudioAttributes = AudioAttributes.Builder()
            .setUsage(AudioAttributes.USAGE_ASSISTANCE_NAVIGATION_GUIDANCE)
            .setContentType(AudioAttributes.CONTENT_TYPE_SPEECH)
            .build()

        /** Ilova tili, keyin ruscha, keyin inglizcha — dvigatelda birinchi mavjud bo'lgani */
        fun pickLanguage(tts: TextToSpeech, locale: String): String? {
            val order = listOf(AppLocale.normalize(locale), "ru", "en").distinct()
            return order.firstOrNull { code ->
                val result = tts.setLanguage(AppLocale.speechLocale(code))
                result != TextToSpeech.LANG_MISSING_DATA && result != TextToSpeech.LANG_NOT_SUPPORTED
            }
        }

        /** Shu tildagi o'rnatilgan ovozlar (tanlov ro'yxati uchun) */
        fun voicesFor(tts: TextToSpeech, lang: String): List<Voice> {
            val language = AppLocale.speechLocale(lang).language
            return tts.voices.orEmpty()
                .filter { it.locale.language == language && TextToSpeech.Engine.KEY_FEATURE_NOT_INSTALLED !in it.features }
                .sortedWith(compareBy({ it.isNetworkConnectionRequired }, { it.name }))
        }

        /** Tashxis: dvigatel va tillar holati (ovoz topilmaganda ko'rsatiladi) */
        fun describe(tts: TextToSpeech, status: Int): String {
            if (status != TextToSpeech.SUCCESS) return "init $status · engines ${tts.engines.map { it.name }}"
            val langs = listOf("uz", "ru", "en").joinToString(" ") { "$it=${tts.isLanguageAvailable(AppLocale.speechLocale(it))}" }
            return "engine ${tts.defaultEngine} · engines ${tts.engines.map { it.name }} · $langs · voices ${tts.voices?.size ?: -1}"
        }

        fun sample(lang: String): String = when (lang) {
            "ru" -> "Через 200 метров поверните направо, на улицу Мустакиллик"
            "en" -> "In 200 meters turn right onto Mustaqillik street"
            else -> "200 metrdan keyin o'ngga buriling, Mustaqillik ko'chasi"
        }
    }
}
