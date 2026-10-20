package uz.ibtaxi.driver.ui

import androidx.compose.ui.draw.clip
import androidx.compose.foundation.layout.size
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
@OptIn(androidx.compose.foundation.layout.ExperimentalLayoutApi::class)
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
            androidx.compose.foundation.layout.FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                uz.ibtaxi.driver.data.AppLocale.codes.forEach { code ->
                    val label = uz.ibtaxi.driver.data.AppLocale.labels.getValue(code)
                    if (app.session.locale == code) Button(onClick = {}) { Text(label) } else OutlinedButton(onClick = { onLocaleChange(code) }) { Text(label) }
                }
            }
            Text(stringResource(R.string.theme), style = MaterialTheme.typography.titleMedium)
            val themeMode by DriverState.themeMode.collectAsState()
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                listOf("system" to R.string.theme_system, "light" to R.string.theme_light, "dark" to R.string.theme_dark).forEach { (mode, label) ->
                    val select = {
                        app.session.themeMode = mode
                        DriverState.setThemeMode(mode)
                    }
                    if (themeMode == mode) Button(onClick = {}) { Text(stringResource(label)) } else OutlinedButton(onClick = select) { Text(stringResource(label)) }
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
        AppLogo()
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
internal fun localTime(iso: String?, pattern: String = "HH:mm"): String? = iso?.let {
    runCatching {
        java.time.OffsetDateTime.parse(it).atZoneSameInstant(java.time.ZoneId.systemDefault())
            .format(java.time.format.DateTimeFormatter.ofPattern(pattern))
    }.getOrNull()
}

/** Dtaxi belgisi — sariq plitkada (kirish va ulanish ekranlari) */
@Composable
fun AppLogo(size: Int = 88) {
    androidx.compose.foundation.Image(
        painter = androidx.compose.ui.res.painterResource(R.drawable.ic_launcher),
        contentDescription = stringResource(R.string.app_name),
        modifier = Modifier.size(size.dp).clip(androidx.compose.foundation.shape.RoundedCornerShape((size / 4).dp)),
    )
}
