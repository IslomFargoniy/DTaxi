package uz.ibtaxi.driver.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.delay
import uz.ibtaxi.driver.R
import uz.ibtaxi.driver.data.Offer
import uz.ibtaxi.driver.service.OfferSound

/**
 * Efirdan taklif (qaror #28): signal, taymer, masofa, manzil. Operator tayinlagan bo'lsa — rad etish jarimali.
 */
@Composable
fun OfferDialog(offer: Offer, onAccept: () -> Unit, onReject: () -> Unit, onExpire: () -> Unit) {
    val context = LocalContext.current
    var left by remember(offer.offerId) { mutableIntStateOf(offer.expiresIn) }

    // Ovoz bildirishnoma bilan umumiy (OfferSound) — oyna ochilsa ham ikkinchi signal chalinmaydi
    DisposableEffect(offer.offerId) {
        OfferSound.start(context, offer.offerId, left)
        onDispose { OfferSound.stop() }
    }
    LaunchedEffect(offer.offerId) {
        while (left > 0) {
            delay(1000)
            left--
        }
        onExpire()
    }

    AlertDialog(
        onDismissRequest = {},
        title = { Text(if (offer.isDelivery) "📦 ${offer.tariffClass}" else "${stringResource(R.string.new_order)} · ${offer.tariffClass}") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                if (offer.isDirect) Text(stringResource(R.string.assigned_by_operator), color = MaterialTheme.colorScheme.error)
                offer.distanceM?.let {
                    Text("${stringResource(R.string.distance_to_client)}: ${"%.1f".format(it / 1000.0)} ${stringResource(R.string.km)}", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
                }
                Text("${stringResource(R.string.pickup)}: ${offer.pickupText}", style = MaterialTheme.typography.titleMedium)
                offer.stopsText?.let { Text("${stringResource(R.string.stops)}: $it") }
                offer.dropoffText?.let { Text("${stringResource(R.string.dropoff)}: $it") }
                offer.priceEstimate?.let { Text("${stringResource(R.string.price_estimate)}: ${formatMoney(it)} ${stringResource(R.string.som)}") }
                offer.priceBonus?.takeIf { it > 0 }?.let {
                    Text("+${formatMoney(it)} ${stringResource(R.string.som)}", color = Color(0xFF16A34A), style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                }
                if (offer.isCorporate) Text(stringResource(R.string.corporate_offer), color = MaterialTheme.colorScheme.tertiary, fontWeight = FontWeight.Bold)
                offer.comment?.let { Text("${stringResource(R.string.comment)}: $it") }
                LinearProgressIndicator(progress = { left.toFloat() / offer.expiresIn.coerceAtLeast(1) }, modifier = Modifier.fillMaxWidth().height(6.dp))
            }
        },
        confirmButton = {
            Row(Modifier.fillMaxWidth().padding(top = 8.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedButton(onClick = onReject, modifier = Modifier.weight(1f)) { Text(stringResource(R.string.reject), maxLines = 1, softWrap = false) }
                Button(onClick = onAccept, modifier = Modifier.weight(1.4f)) { Text("${stringResource(R.string.accept)} ($left)", maxLines = 1) }
            }
        },
    )
}
