package uz.dtaxi.driver.ui

import android.os.SystemClock
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import kotlinx.coroutines.delay
import uz.dtaxi.driver.R
import uz.dtaxi.driver.data.Offer
import uz.dtaxi.driver.service.OfferSound
import kotlin.math.ceil

/**
 * Efirdan taklif (qaror #28, maket A-Offer): aylana taymer, masofa, narx, manzillar va katta "Qabul qilish".
 * Operator tayinlagan bo'lsa — rad etish jarimali.
 */
@Composable
fun OfferDialog(offer: Offer, onAccept: () -> Unit, onReject: () -> Unit, onExpire: () -> Unit, accepting: Boolean = false) {
    val context = LocalContext.current
    val appColors = LocalAppColors.current
    // Qolgan vaqt taklif kelgan paytdan sanaladi — ekran burilsa yoki qayta yaratilsa taymer boshidan boshlanmaydi
    var left by remember(offer) { mutableIntStateOf(offer.secondsLeft(SystemClock.elapsedRealtime())) }
    if (left <= 0) {
        // Muddati o'tgan taklif hech qachon ko'rsatilmaydi
        LaunchedEffect(offer) { onExpire() }
        return
    }

    // Ovoz bildirishnoma bilan umumiy (OfferSound) — oyna ochilsa ham ikkinchi signal chalinmaydi
    DisposableEffect(offer) {
        OfferSound.start(context, offer.offerId, left)
        onDispose { OfferSound.stop() }
    }
    LaunchedEffect(offer) {
        while (true) {
            val millis = offer.millisLeft(SystemClock.elapsedRealtime())
            left = ((millis + 999) / 1000).toInt()
            if (millis <= 0) break
            delay(millis.coerceAtMost(250))
        }
        onExpire()
    }

    Dialog(
        onDismissRequest = {},
        properties = DialogProperties(usePlatformDefaultWidth = false, dismissOnBackPress = false, dismissOnClickOutside = false),
    ) {
        Box(Modifier.fillMaxSize().background(Color(0x59111111))) {
            Surface(
                Modifier.align(Alignment.BottomCenter).fillMaxWidth().padding(12.dp).navigationBarsPadding(),
                shape = RoundedCornerShape(28.dp),
                color = MaterialTheme.colorScheme.surface,
                shadowElevation = 16.dp,
            ) {
                Column(Modifier.padding(20.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Box(Modifier.size(64.dp), contentAlignment = Alignment.Center) {
                            CircularProgressIndicator(
                                progress = { left.toFloat() / offer.expiresIn.coerceAtLeast(1) },
                                modifier = Modifier.size(64.dp),
                                color = MaterialTheme.colorScheme.primary,
                                trackColor = appColors.tile,
                                strokeWidth = 6.dp,
                            )
                            Text("$left", style = MaterialTheme.typography.titleLarge)
                        }
                        Column(Modifier.padding(start = 14.dp)) {
                            Text(
                                "${if (offer.isDelivery) "📦 " else ""}${stringResource(R.string.new_order).uppercase()} · ${offer.tariffClass}",
                                style = MaterialTheme.typography.labelMedium,
                                fontWeight = FontWeight.Bold,
                                color = appColors.muted,
                            )
                            offer.distanceM?.let {
                                // Shahar ichida o'rtacha ~25 km/soat — taxminiy yetib borish vaqti
                                val minutes = ceil(it / (25_000.0 / 60)).toInt().coerceAtLeast(1)
                                Text(
                                    "${"%.1f".format(it / 1000.0)} ${stringResource(R.string.km)} · $minutes ${stringResource(R.string.minutes_short)}",
                                    style = MaterialTheme.typography.headlineSmall,
                                )
                            }
                        }
                    }

                    if (offer.isDirect) Text(stringResource(R.string.assigned_by_operator), color = MaterialTheme.colorScheme.error, fontWeight = FontWeight.Bold)

                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        offer.priceEstimate?.let {
                            Text("~${formatMoney(it)}", style = MaterialTheme.typography.headlineMedium)
                            Text(stringResource(R.string.som), fontWeight = FontWeight.Bold, color = appColors.muted)
                        }
                        Box(Modifier.weight(1f))
                        offer.priceBonus?.takeIf { it > 0 }?.let {
                            Surface(shape = RoundedCornerShape(999.dp), color = MaterialTheme.colorScheme.tertiaryContainer) {
                                Text(
                                    "+${formatMoney(it)} ${stringResource(R.string.bonus).lowercase()}",
                                    Modifier.padding(horizontal = 10.dp, vertical = 6.dp),
                                    color = MaterialTheme.colorScheme.onTertiaryContainer,
                                    fontWeight = FontWeight.ExtraBold,
                                )
                            }
                        }
                    }

                    if (offer.isCorporate) {
                        Surface(shape = RoundedCornerShape(999.dp), color = MaterialTheme.colorScheme.tertiaryContainer) {
                            Text(
                                stringResource(R.string.corporate_offer),
                                Modifier.padding(horizontal = 12.dp, vertical = 6.dp),
                                color = MaterialTheme.colorScheme.onTertiaryContainer,
                                fontWeight = FontWeight.Bold,
                            )
                        }
                    }

                    Column(
                        Modifier.fillMaxWidth().background(appColors.tile, RoundedCornerShape(18.dp)).padding(14.dp),
                        verticalArrangement = Arrangement.spacedBy(10.dp),
                    ) {
                        PlaceRow(PlaceDot.Pickup, offer.pickupText)
                        // Bo'sh satr (FCM'da null "" bo'lib keladi) — bo'sh qator chizilmasin
                        offer.stopsText?.takeIf { it.isNotBlank() }?.let { PlaceRow(PlaceDot.Stop, "${stringResource(R.string.stops)}: $it", bold = false) }
                        offer.dropoffText?.takeIf { it.isNotBlank() }?.let { PlaceRow(PlaceDot.Dropoff, it) }
                    }

                    offer.comment?.takeIf { it.isNotBlank() }?.let { Text("${stringResource(R.string.comment)}: $it", color = appColors.muted) }

                    Button(
                        onClick = onAccept,
                        enabled = !accepting,
                        modifier = Modifier.fillMaxWidth().height(64.dp),
                        shape = RoundedCornerShape(20.dp),
                    ) {
                        if (accepting) {
                            CircularProgressIndicator(Modifier.size(28.dp), color = MaterialTheme.colorScheme.onPrimary, strokeWidth = 3.dp)
                        } else {
                            Text(stringResource(R.string.accept), style = MaterialTheme.typography.titleLarge)
                        }
                    }
                    TextButton(onClick = onReject, enabled = !accepting, modifier = Modifier.fillMaxWidth().height(48.dp)) {
                        Text(stringResource(R.string.skip), fontWeight = FontWeight.Bold, color = appColors.muted)
                    }
                }
            }
        }
    }
}
