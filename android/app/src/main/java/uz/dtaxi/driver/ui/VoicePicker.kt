package uz.dtaxi.driver.ui

import android.content.Intent
import android.speech.tts.TextToSpeech
import android.speech.tts.Voice
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.KeyboardArrowRight
import androidx.compose.material3.AlertDialog
import androidx.compose.ui.unit.dp
import androidx.compose.material.icons.filled.PlayArrow
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.RadioButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import uz.dtaxi.driver.DtaxiApp
import uz.dtaxi.driver.R
import uz.dtaxi.driver.navigation.VoiceGuide

/**
 * Navigatsiya ovozi: telefondagi TTS ovozlari ro'yxati, har birini eshitib tanlash.
 * Boshqa dvigatel (Samsung, RHVoice) o'rnatilsa — telefon sozlamalarida standart qilinadi va shu yerda ko'rinadi.
 */
@Composable
fun VoicePicker() {
    val context = LocalContext.current
    val session = DtaxiApp.instance.session
    var lang by remember { mutableStateOf<String?>(null) }
    var voices by remember { mutableStateOf<List<Voice>>(emptyList()) }
    var ready by remember { mutableStateOf(false) }
    var diag by remember { mutableStateOf("") }
    var selected by remember { mutableStateOf(session.navVoice) }
    val tts = remember {
        var engine: TextToSpeech? = null
        engine = TextToSpeech(context.applicationContext) { status ->
            val e = engine ?: return@TextToSpeech
            if (status == TextToSpeech.SUCCESS) {
                lang = VoiceGuide.pickLanguage(e, session.locale)
                voices = lang?.let { VoiceGuide.voicesFor(e, it) }.orEmpty()
            }
            diag = VoiceGuide.describe(e, status)
            ready = true
        }
        engine.setAudioAttributes(VoiceGuide.navigationAudio)
        engine
    }
    DisposableEffect(Unit) { onDispose { tts.shutdown() } }

    fun listen(voice: Voice?) {
        val code = lang ?: return
        tts.setLanguage(uz.dtaxi.driver.data.AppLocale.speechLocale(code))
        if (voice != null) tts.voice = voice
        tts.speak(VoiceGuide.sample(code), TextToSpeech.QUEUE_FLUSH, null, "sample")
    }

    fun choose(name: String?) {
        selected = name
        session.navVoice = name
    }

    fun label(i: Int, voice: Voice): String = context.getString(R.string.nav_voice_n, i + 1) +
        (if (voice.isNetworkConnectionRequired) " · " + context.getString(R.string.nav_voice_online) else "")

    var open by remember { mutableStateOf(false) }
    val current = voices.indexOfFirst { it.name == selected }
        .takeIf { it >= 0 }?.let { label(it, voices[it]) } ?: stringResource(R.string.nav_voice_default)
    Row(Modifier.fillMaxWidth().clickable { open = true }.padding(vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
        Text(stringResource(R.string.nav_voice), Modifier.weight(1f), style = MaterialTheme.typography.titleMedium)
        Text(current, color = LocalAppColors.current.muted)
        Icon(Icons.AutoMirrored.Filled.KeyboardArrowRight, null, tint = LocalAppColors.current.muted)
    }

    if (open) {
        AlertDialog(
            onDismissRequest = {
                tts.stop()
                open = false
            },
            title = { Text(stringResource(R.string.nav_voice)) },
            text = {
                Column(Modifier.heightIn(max = 420.dp).verticalScroll(rememberScrollState())) {
                    if (ready && voices.isEmpty()) {
                        Text(stringResource(R.string.nav_voice_none), color = LocalAppColors.current.muted)
                        // Nima yetishmayotganini ko'rish uchun (dvigatel, tillar holati)
                        Text(diag, style = MaterialTheme.typography.bodySmall, color = LocalAppColors.current.muted)
                    }
                    if (lang != null) {
                        VoiceRow(stringResource(R.string.nav_voice_default), null, selected == null, { choose(null) }, { listen(null) })
                        voices.forEachIndexed { i, voice ->
                            VoiceRow(label(i, voice), voice.name, selected == voice.name, { choose(voice.name) }, { listen(voice) })
                        }
                    }
                    TextButton(onClick = {
                        runCatching { context.startActivity(Intent("com.android.settings.TTS_SETTINGS").addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) }
                    }) { Text(stringResource(R.string.nav_voice_more)) }
                }
            },
            confirmButton = {
                TextButton(onClick = {
                    tts.stop()
                    open = false
                }) { Text(stringResource(R.string.done)) }
            },
        )
    }
}

@Composable
private fun VoiceRow(label: String, detail: String?, checked: Boolean, onSelect: () -> Unit, onListen: () -> Unit) {
    Row(Modifier.fillMaxWidth().clickable(onClick = onSelect), verticalAlignment = Alignment.CenterVertically) {
        RadioButton(selected = checked, onClick = onSelect)
        Column(Modifier.weight(1f)) {
            Text(label)
            if (detail != null) Text(detail, style = MaterialTheme.typography.bodySmall, color = LocalAppColors.current.muted)
        }
        IconButton(onClick = onListen) { Icon(Icons.Default.PlayArrow, stringResource(R.string.nav_voice_listen)) }
    }
}
