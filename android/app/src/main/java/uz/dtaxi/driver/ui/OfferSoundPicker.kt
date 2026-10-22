package uz.dtaxi.driver.ui

import android.app.Activity
import android.content.Intent
import android.media.RingtoneManager
import android.net.Uri
import android.os.Build
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.KeyboardArrowRight
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import uz.dtaxi.driver.DtaxiApp
import uz.dtaxi.driver.R
import uz.dtaxi.driver.service.OfferSound

/**
 * Yangi buyurtma signali: Android'ning o'z ovoz tanlash oynasi (budilnik, qo'ng'iroq va bildirishnoma ovozlari,
 * har birini eshitib ko'rish mumkin). Jimjit variant berilmaydi — taklif o'tkazib yuborilsa haydovchi jarimaga tushadi.
 */
@Composable
fun OfferSoundPicker() {
    val context = LocalContext.current
    val session = DtaxiApp.instance.session
    var chosen by remember { mutableStateOf(session.offerSound) }
    val title = remember(chosen) {
        chosen?.let { uri -> runCatching { RingtoneManager.getRingtone(context, Uri.parse(uri))?.getTitle(context) }.getOrNull() }
    }

    val launcher = rememberLauncherForActivityResult(ActivityResultContracts.StartActivityForResult()) { result ->
        if (result.resultCode != Activity.RESULT_OK) return@rememberLauncherForActivityResult
        val uri = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            result.data?.getParcelableExtra(RingtoneManager.EXTRA_RINGTONE_PICKED_URI, Uri::class.java)
        } else {
            @Suppress("DEPRECATION")
            result.data?.getParcelableExtra(RingtoneManager.EXTRA_RINGTONE_PICKED_URI)
        }
        // Standart tanlansa — saqlanmaydi (telefon budilnik ovozi o'zgarsa ham shu ishlaydi)
        val value = uri?.takeIf { it != OfferSound.defaultUri() && !RingtoneManager.isDefault(it) }?.toString()
        session.offerSound = value
        chosen = value
    }

    Row(
        Modifier.fillMaxWidth().clickable {
            val intent = Intent(RingtoneManager.ACTION_RINGTONE_PICKER)
                .putExtra(RingtoneManager.EXTRA_RINGTONE_TYPE, RingtoneManager.TYPE_ALL)
                .putExtra(RingtoneManager.EXTRA_RINGTONE_TITLE, context.getString(R.string.offer_sound))
                .putExtra(RingtoneManager.EXTRA_RINGTONE_SHOW_DEFAULT, true)
                .putExtra(RingtoneManager.EXTRA_RINGTONE_DEFAULT_URI, OfferSound.defaultUri())
                .putExtra(RingtoneManager.EXTRA_RINGTONE_SHOW_SILENT, false)
                .putExtra(RingtoneManager.EXTRA_RINGTONE_EXISTING_URI, OfferSound.soundUri(context))
            runCatching { launcher.launch(intent) }.onFailure { uz.dtaxi.driver.data.DriverState.toast(context.getString(R.string.no_app_found)) }
        }.padding(vertical = 4.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(stringResource(R.string.offer_sound), Modifier.weight(1f), style = MaterialTheme.typography.titleMedium)
        Text(
            title ?: stringResource(R.string.offer_sound_default),
            Modifier.weight(1f, fill = false).padding(start = 8.dp),
            color = LocalAppColors.current.muted,
            maxLines = 1,
            overflow = TextOverflow.Ellipsis,
        )
        Icon(Icons.AutoMirrored.Filled.KeyboardArrowRight, null, tint = LocalAppColors.current.muted)
    }
}
