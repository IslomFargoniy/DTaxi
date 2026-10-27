package uz.dtaxi.client.ui

import android.content.Context
import android.content.Intent
import android.net.Uri
import androidx.activity.compose.BackHandler
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.Star
import androidx.compose.material.icons.filled.StarBorder
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.FilterChip
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch
import uz.dtaxi.client.BuildConfig
import uz.dtaxi.client.ClientApp
import uz.dtaxi.client.R
import uz.dtaxi.client.data.ClientOrder
import uz.dtaxi.client.data.Profile
import uz.dtaxi.client.data.Result
import uz.dtaxi.core.data.Formats
import uz.dtaxi.core.ui.LanguageRow
import uz.dtaxi.core.ui.LocalAppColors
import uz.dtaxi.core.ui.formatMoney
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter

/** Safardan keyin baho (1–5) — haydovchi reytingiga ta'sir qiladi */
@Composable
fun RateDialog(order: ClientOrder) {
    val repository = ClientApp.instance.repository
    val scope = rememberCoroutineScope()
    var rating by remember { mutableStateOf(0) }
    // Ikki marta bosilsa ikkita so'rov ketmasin
    var sending by remember { mutableStateOf(false) }
    AlertDialog(
        // Oyna tashqarisiga bosish ham "Keyinroq" — keyingi ochilishda qayta chiqmaydi
        onDismissRequest = { if (!sending) repository.dismissRating(order.id) },
        title = { Text(stringResource(R.string.rate_title)) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                order.priceFinal?.let { Text(stringResource(R.string.price, formatMoney(it)), fontWeight = FontWeight.Bold) }
                Row {
                    (1..5).forEach { star ->
                        IconButton(onClick = { rating = star }, enabled = !sending) {
                            Icon(
                                if (star <= rating) Icons.Default.Star else Icons.Default.StarBorder,
                                contentDescription = star.toString(),
                                tint = MaterialTheme.colorScheme.primary,
                            )
                        }
                    }
                }
            }
        },
        confirmButton = {
            TextButton(onClick = {
                sending = true
                scope.launch { if (repository.rate(order.id, rating) is Result.Error) sending = false }
            }, enabled = rating > 0 && !sending) { Text(stringResource(R.string.save)) }
        },
        dismissButton = { TextButton(onClick = { repository.dismissRating(order.id) }, enabled = !sending) { Text(stringResource(R.string.later)) } },
    )
}

/** Server sahifasi hajmi (OrderController::index) — kamroq kelsa oxiri */
private const val HISTORY_PAGE = 20

private val HISTORY_TIME: DateTimeFormatter = DateTimeFormatter.ofPattern("dd.MM.yyyy HH:mm")

/** Server vaqti (ISO 8601, filial mintaqasida) — telefonning mahalliy vaqtida */
internal fun localTime(iso: String): String = runCatching {
    OffsetDateTime.parse(iso).atZoneSameInstant(ZoneId.systemDefault()).format(HISTORY_TIME)
}.getOrElse { iso.take(16).replace('T', ' ') }

/** canReorder — faol buyurtma yo'q bo'lsa "Qayta buyurtma" (manzillar va tarif qoralamaga) */
@Composable
fun HistoryScreen(onBack: () -> Unit, canReorder: Boolean, onReorder: (ClientOrder) -> Unit) {
    val repository = ClientApp.instance.repository
    var orders by remember { mutableStateOf<List<ClientOrder>?>(null) }
    var end by remember { mutableStateOf(false) }
    var failed by remember { mutableStateOf(false) }
    var attempt by remember { mutableIntStateOf(0) }
    BackHandler(onBack = onBack)

    // Keyingi sahifa: ro'yxat oxiri ko'ringanda (pastdagi yuklash qatori chizilganda)
    suspend fun loadMore() {
        val loaded = orders.orEmpty()
        when (val result = repository.history(loaded.lastOrNull()?.id)) {
            is Result.Ok -> {
                orders = loaded + result.value.filter { new -> loaded.none { it.id == new.id } }
                end = result.value.size < HISTORY_PAGE
            }
            is Result.Error -> failed = true
        }
    }

    Column(Modifier.fillMaxSize().statusBarsPadding()) {
        TopRow(stringResource(R.string.history), onBack)
        val list = orders
        if (end && list != null && list.isEmpty()) {
            Text(stringResource(R.string.history_empty), Modifier.padding(16.dp), color = LocalAppColors.current.muted)
        }
        LazyColumn(Modifier.navigationBarsPadding()) {
            items(list.orEmpty(), key = { it.id }) { order ->
                Column(Modifier.fillMaxWidth().padding(16.dp), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text(localTime(order.createdAt), Modifier.weight(1f), color = LocalAppColors.current.muted)
                        (order.priceFinal ?: order.priceEstimate)?.let { Text(stringResource(R.string.price, formatMoney(it)), fontWeight = FontWeight.Bold) }
                    }
                    Text(order.pickup.text.orEmpty())
                    order.dropoff?.text?.let { Text("→ $it") }
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text(statusText(order.status), Modifier.weight(1f), style = MaterialTheme.typography.bodySmall, color = LocalAppColors.current.muted)
                        if (canReorder && !order.isActive) {
                            TextButton(onClick = { onReorder(order) }) { Text(stringResource(R.string.order_again)) }
                        }
                    }
                }
                HorizontalDivider()
            }
            if (!end) {
                item(key = "more") {
                    Box(Modifier.fillMaxWidth().padding(16.dp), contentAlignment = Alignment.Center) {
                        if (failed) {
                            TextButton(onClick = {
                                failed = false
                                attempt++
                            }) { Text(stringResource(R.string.network_error)) }
                        } else {
                            CircularProgressIndicator(Modifier.size(24.dp))
                            LaunchedEffect(list?.size, attempt) { loadMore() }
                        }
                    }
                }
            }
        }
    }
}

@Composable
fun SettingsScreen(profile: Profile, onLocaleChange: (String) -> Unit, onThemeChange: (String) -> Unit, onBack: () -> Unit) {
    val repository = ClientApp.instance.repository
    val session = ClientApp.instance.session
    val scope = rememberCoroutineScope()
    var confirmLogout by remember { mutableStateOf(false) }
    var confirmDelete by remember { mutableStateOf(false) }
    val context = LocalContext.current
    var theme by remember { mutableStateOf(session.themeMode) }
    BackHandler(onBack = onBack)

    Column(Modifier.fillMaxSize().statusBarsPadding()) {
        TopRow(stringResource(R.string.settings), onBack)
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(16.dp)) {
            Text(profile.name.orEmpty(), style = MaterialTheme.typography.titleLarge)
            Text(Formats.formatPhone(profile.phone), color = LocalAppColors.current.muted)
            Text(stringResource(R.string.language), style = MaterialTheme.typography.labelLarge)
            LanguageRow(session.locale, onSelect = { if (session.locale != it) onLocaleChange(it) })
            Text(stringResource(R.string.theme), style = MaterialTheme.typography.labelLarge)
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                listOf("system" to R.string.theme_system, "light" to R.string.theme_light, "dark" to R.string.theme_dark).forEach { (mode, label) ->
                    FilterChip(selected = theme == mode, onClick = {
                        theme = mode
                        onThemeChange(mode)
                    }, label = { Text(stringResource(label)) })
                }
            }
            OutlinedButton(onClick = { confirmLogout = true }, modifier = Modifier.fillMaxWidth()) { Text(stringResource(R.string.logout)) }
            TextButton(onClick = { openPrivacy(context) }) { Text(stringResource(R.string.privacy_policy)) }
            TextButton(onClick = { confirmDelete = true }) {
                Text(stringResource(R.string.delete_account), color = MaterialTheme.colorScheme.error)
            }
        }
    }

    if (confirmDelete) {
        AlertDialog(
            onDismissRequest = { confirmDelete = false },
            title = { Text(stringResource(R.string.delete_account)) },
            text = { Text(stringResource(R.string.delete_account_text)) },
            confirmButton = {
                TextButton(onClick = {
                    confirmDelete = false
                    scope.launch { repository.deleteAccount() }
                }) { Text(stringResource(R.string.delete), color = MaterialTheme.colorScheme.error) }
            },
            dismissButton = { TextButton(onClick = { confirmDelete = false }) { Text(stringResource(R.string.cancel)) } },
        )
    }

    if (confirmLogout) {
        AlertDialog(
            onDismissRequest = { confirmLogout = false },
            title = { Text(stringResource(R.string.logout_confirm)) },
            confirmButton = {
                TextButton(onClick = {
                    confirmLogout = false
                    scope.launch { repository.logout() }
                }) { Text(stringResource(R.string.logout)) }
            },
            dismissButton = { TextButton(onClick = { confirmLogout = false }) { Text(stringResource(R.string.cancel)) } },
        )
    }
}

/** Maxfiylik siyosati — firma serverida (Google Play'da ham shu manzil) */
fun openPrivacy(context: Context) {
    runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(BuildConfig.SERVER_URL + "/privacy"))) }
}

@Composable
private fun TopRow(title: String, onBack: () -> Unit) {
    Row(Modifier.padding(8.dp), verticalAlignment = Alignment.CenterVertically) {
        IconButton(onClick = onBack) { Icon(Icons.AutoMirrored.Filled.ArrowBack, stringResource(R.string.back)) }
        Text(title, style = MaterialTheme.typography.titleLarge)
    }
}
