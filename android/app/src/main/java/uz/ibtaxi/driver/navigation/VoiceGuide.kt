package uz.ibtaxi.driver.navigation

import android.content.Context
import android.speech.tts.TextToSpeech

/**
 * Ovozli ko'rsatmalar: telefonning TTS dvigateli. Ilova tili (o'zbek/rus) dvigatelda bo'lmasa — jim
 * (banner baribir ko'rinadi). Internet kerak emas.
 */
class VoiceGuide(context: Context, private val locale: String) {
    private var ready = false
    private val tts: TextToSpeech = TextToSpeech(context.applicationContext) { status ->
        if (status == TextToSpeech.SUCCESS) {
            val result = setLanguage()
            ready = result != TextToSpeech.LANG_MISSING_DATA && result != TextToSpeech.LANG_NOT_SUPPORTED
        }
    }

    val available: Boolean get() = ready

    private fun setLanguage(): Int = tts.setLanguage(uz.ibtaxi.driver.data.AppLocale.speechLocale(locale))

    fun say(text: String) {
        if (ready) tts.speak(text, TextToSpeech.QUEUE_FLUSH, null, "nav")
    }

    fun shutdown() = tts.shutdown()
}
