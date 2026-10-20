package uz.ibtaxi.driver.ui

import android.content.Intent
import android.net.Uri
import android.provider.Settings
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.automirrored.filled.Send
import androidx.compose.material3.AssistChip
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.ui.platform.LocalClipboardManager
import androidx.compose.ui.text.AnnotatedString
import uz.ibtaxi.driver.data.PaymentsConfig
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import uz.ibtaxi.driver.IbTaxiApp
import uz.ibtaxi.driver.R
import uz.ibtaxi.driver.data.ChatMessage
import uz.ibtaxi.driver.data.DriverState
import uz.ibtaxi.driver.data.Result
import uz.ibtaxi.driver.data.Transaction
import uz.ibtaxi.driver.service.DriverService

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun ScreenScaffold(title: String, onBack: () -> Unit, content: @Composable (Modifier) -> Unit) {
    Scaffold(topBar = {
        TopAppBar(title = { Text(title) }, navigationIcon = {
            IconButton(onClick = onBack) { Icon(Icons.AutoMirrored.Filled.ArrowBack, null) }
        })
    }) { padding -> content(Modifier.padding(padding)) }
}

/**
 * Dispetcher bilan chat (qaror #47): tezkor javoblar; yangi xabarlar Reverb orqali.
 */
@Composable
fun ChatScreen(onBack: () -> Unit) {
    val repository = IbTaxiApp.instance.repository
    val scope = rememberCoroutineScope()
    val messages = remember { mutableStateListOf<ChatMessage>() }
    val unread by DriverState.unread.collectAsState()
    val order by DriverState.order.collectAsState()
    var text by remember { mutableStateOf("") }
    val listState = rememberLazyListState()
    val quick = listOf(
        stringResource(R.string.quick_client_late),
        stringResource(R.string.quick_address),
        stringResource(R.string.quick_on_way),
        stringResource(R.string.quick_traffic),
    )

    suspend fun load() {
        (repository.chat() as? Result.Ok)?.value?.let {
            messages.clear()
            messages.addAll(it.reversed())
            DriverState.setUnread(0)
        }
    }
    LaunchedEffect(unread) { load() }
    LaunchedEffect(messages.size) { if (messages.isNotEmpty()) listState.animateScrollToItem(messages.size - 1) }

    fun send(body: String) {
        if (body.isBlank()) return
        scope.launch {
            (repository.sendChat(body, order?.id) as? Result.Ok)?.value?.let { messages.add(it) }
        }
        text = ""
    }

    ScreenScaffold(stringResource(R.string.chat), onBack) { modifier ->
        Column(modifier.fillMaxSize()) {
            LazyColumn(Modifier.weight(1f).padding(12.dp), state = listState, verticalArrangement = Arrangement.spacedBy(6.dp)) {
                items(messages, key = { it.id }) { m ->
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = if (m.mine) Arrangement.End else Arrangement.Start) {
                        Column(
                            Modifier.widthIn(max = 280.dp)
                                .background(if (m.mine) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.surfaceVariant, RoundedCornerShape(12.dp))
                                .padding(horizontal = 12.dp, vertical = 8.dp),
                        ) {
                            // Kim va qachon yozgani: operator ismi (bir nechta dispetcher bo'lishi mumkin)
                            Row {
                                Text(
                                    if (m.mine) stringResource(R.string.you) else "${stringResource(R.string.dispatcher)}: ${m.senderName ?: ""}",
                                    style = MaterialTheme.typography.labelSmall,
                                    fontWeight = FontWeight.Bold,
                                )
                                localTime(m.createdAt)?.let { Text("  $it", style = MaterialTheme.typography.labelSmall) }
                            }
                            Text(m.body ?: "📷")
                        }
                    }
                }
            }
            Row(Modifier.padding(horizontal = 8.dp), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                quick.take(2).forEach { AssistChip(onClick = { send(it) }, label = { Text(it) }) }
            }
            Row(Modifier.padding(horizontal = 8.dp), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                quick.drop(2).forEach { AssistChip(onClick = { send(it) }, label = { Text(it) }) }
            }
            Row(Modifier.padding(8.dp), verticalAlignment = Alignment.CenterVertically) {
                OutlinedTextField(value = text, onValueChange = { text = it }, placeholder = { Text(stringResource(R.string.message)) }, modifier = Modifier.weight(1f))
                IconButton(onClick = { send(text) }) { Icon(Icons.AutoMirrored.Filled.Send, null) }
            }
        }
    }
}

/**
 * Balans va tarix; to'ldirish yo'riqnomasi (qaror #23): Click/Payme'da ID yoki kassa.
 */
@Composable
fun BalanceScreen(onBack: () -> Unit) {
    val repository = IbTaxiApp.instance.repository
    val profile by DriverState.profile.collectAsState()
    var items by remember { mutableStateOf<List<Transaction>>(emptyList()) }
    LaunchedEffect(Unit) { (repository.balance() as? Result.Ok)?.value?.let { items = it.items } }

    ScreenScaffold(stringResource(R.string.history), onBack) { modifier ->
        LazyColumn(modifier.fillMaxSize().padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            item {
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(16.dp)) {
                        Text("${formatMoney(profile?.balance)} ${stringResource(R.string.som)}", style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Bold)
                        Text("${stringResource(R.string.your_id)}: ${profile?.callsign}", style = MaterialTheme.typography.titleMedium)
                        Text(stringResource(R.string.top_up_hint), style = MaterialTheme.typography.bodySmall)
                    }
                }
            }
            profile?.let { p -> item { TopUpCard(p.callsign, p.config.payments) } }
            items(items) { t ->
                Row(Modifier.fillMaxWidth()) {
                    Column(Modifier.weight(1f)) {
                        Text(t.comment ?: transactionLabel(t.type))
                        Text(localTime(t.createdAt, "dd.MM.yyyy HH:mm") ?: "", style = MaterialTheme.typography.bodySmall)
                    }
                    Text(
                        (if (t.amount > 0) "+" else "") + formatMoney(t.amount),
                        color = if (t.amount < 0) MaterialTheme.colorScheme.error else Color(0xFF16A34A),
                        fontWeight = FontWeight.Bold,
                    )
                }
                HorizontalDivider()
            }
        }
    }
}

@Composable
private fun transactionLabel(type: String): String = when (type) {
    "commission" -> stringResource(R.string.tx_commission)
    "topup" -> stringResource(R.string.tx_topup)
    "penalty" -> stringResource(R.string.tx_penalty)
    "bonus" -> stringResource(R.string.tx_bonus)
    "corporate" -> stringResource(R.string.tx_corporate)
    else -> stringResource(R.string.tx_adjustment)
}

/**
 * Balansni to'ldirish (qaror #23): summa tanlanadi, Click/Payme ilovasi (yoki sayti) ID bilan ochiladi.
 */
@Composable
private fun TopUpCard(callsign: String, payments: PaymentsConfig?) {
    val context = LocalContext.current
    val clipboard = LocalClipboardManager.current
    var amount by remember { mutableStateOf(50_000L) }
    val open = { url: String -> context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url))) }

    Card(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Text(stringResource(R.string.top_up), style = MaterialTheme.typography.titleMedium)
            OutlinedButton(onClick = { clipboard.setText(AnnotatedString(callsign)) }) { Text("${stringResource(R.string.copy_id)}: $callsign") }
            if (payments?.click != null || payments?.payme != null) {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    listOf(20_000L, 50_000L, 100_000L).forEach { value ->
                        AssistChip(onClick = { amount = value }, label = { Text(formatMoney(value)) }, colors = if (amount == value) {
                            androidx.compose.material3.AssistChipDefaults.assistChipColors(containerColor = MaterialTheme.colorScheme.primaryContainer)
                        } else {
                            androidx.compose.material3.AssistChipDefaults.assistChipColors()
                        })
                    }
                }
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    payments.click?.let { click ->
                        Button(onClick = {
                            open("https://my.click.uz/services/pay?service_id=${click.serviceId}&merchant_id=${click.merchantId ?: ""}&amount=$amount&transaction_param=$callsign")
                        }) { Text("Click") }
                    }
                    payments.payme?.let { payme ->
                        Button(onClick = {
                            val params = "m=${payme.merchantId};ac.driver_id=$callsign;a=${amount * 100}"
                            open("https://checkout.paycom.uz/" + android.util.Base64.encodeToString(params.toByteArray(), android.util.Base64.NO_WRAP))
                        }) { Text("Payme") }
                    }
                }
            }
        }
    }
}

/**
 * Sozlamalar: til, batareya yo'riqnomasi, chiqish.
 */
@Composable
fun SettingsScreen(onBack: () -> Unit, onLocaleChange: (String) -> Unit) {
    val context = LocalContext.current
    val app = IbTaxiApp.instance
    val scope = rememberCoroutineScope()

    ScreenScaffold(stringResource(R.string.settings), onBack) { modifier ->
        Column(modifier.fillMaxSize().padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            var voice by remember { mutableStateOf(app.session.voiceGuidance) }
            Row(verticalAlignment = androidx.compose.ui.Alignment.CenterVertically) {
                Text(stringResource(R.string.voice_guidance), Modifier.weight(1f), style = MaterialTheme.typography.titleMedium)
                androidx.compose.material3.Switch(checked = voice, onCheckedChange = {
                    voice = it
                    app.session.voiceGuidance = it
                })
            }
            Text(stringResource(R.string.language), style = MaterialTheme.typography.titleMedium)
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                listOf("uz" to "O'zbekcha", "ru" to "Русский").forEach { (code, label) ->
                    if (app.session.locale == code) Button(onClick = {}) { Text(label) } else OutlinedButton(onClick = { onLocaleChange(code) }) { Text(label) }
                }
            }
            HorizontalDivider()
            Text(stringResource(R.string.battery_title), style = MaterialTheme.typography.titleMedium)
            Text(stringResource(R.string.battery_text), style = MaterialTheme.typography.bodyMedium)
            OutlinedButton(onClick = {
                context.startActivity(Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS, Uri.parse("package:${context.packageName}")))
            }) { Text(stringResource(R.string.open_settings)) }
            HorizontalDivider()
            Button(onClick = {
                scope.launch {
                    app.repository.setOnline(false)
                    DriverService.stop(context)
                    delay(200)
                    app.repository.logout()
                }
            }) { Text(stringResource(R.string.logout)) }
        }
    }
}

/**
 * Profil yuklanmoqda yoki server bilan aloqa yo'q: qora ekran o'rniga holat va chiqish imkoni
 * (sinov ilovasida chiqib, login ekranida server manzilini o'zgartirish mumkin).
 */
@Composable
fun ConnectingScreen(error: String?, server: String, onRetry: () -> Unit, onLogout: () -> Unit) {
    Column(
        Modifier.fillMaxSize().padding(24.dp),
        verticalArrangement = Arrangement.spacedBy(16.dp, androidx.compose.ui.Alignment.CenterVertically),
        horizontalAlignment = androidx.compose.ui.Alignment.CenterHorizontally,
    ) {
        if (error == null) {
            androidx.compose.material3.CircularProgressIndicator()
            Text(stringResource(R.string.connecting))
        } else {
            Text(stringResource(R.string.no_connection), style = MaterialTheme.typography.titleLarge)
            Text(error, color = MaterialTheme.colorScheme.error)
            Text("${stringResource(R.string.server_address)}: $server", style = MaterialTheme.typography.bodySmall)
            Button(onClick = onRetry, modifier = Modifier.fillMaxWidth()) { Text(stringResource(R.string.retry)) }
            OutlinedButton(onClick = onLogout, modifier = Modifier.fillMaxWidth()) { Text(stringResource(R.string.logout)) }
        }
    }
}

/** Server vaqti (ISO, UTC) → telefonning mahalliy soati "14:05" */
private fun localTime(iso: String?, pattern: String = "HH:mm"): String? = iso?.let {
    runCatching {
        java.time.OffsetDateTime.parse(it).atZoneSameInstant(java.time.ZoneId.systemDefault())
            .format(java.time.format.DateTimeFormatter.ofPattern(pattern))
    }.getOrNull()
}
