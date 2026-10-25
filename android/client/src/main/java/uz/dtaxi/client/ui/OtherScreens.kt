package uz.dtaxi.client.ui

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.Star
import androidx.compose.material.icons.filled.StarBorder
import androidx.compose.material3.AlertDialog
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
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch
import uz.dtaxi.client.ClientApp
import uz.dtaxi.client.R
import uz.dtaxi.client.data.ClientOrder
import uz.dtaxi.client.data.ClientState
import uz.dtaxi.client.data.Profile
import uz.dtaxi.client.data.Result
import uz.dtaxi.core.data.Formats
import uz.dtaxi.core.ui.LanguageRow
import uz.dtaxi.core.ui.LocalAppColors
import uz.dtaxi.core.ui.formatMoney

/** Safardan keyin baho (1–5) — haydovchi reytingiga ta'sir qiladi */
@Composable
fun RateDialog(order: ClientOrder) {
    val repository = ClientApp.instance.repository
    val scope = rememberCoroutineScope()
    var rating by remember { mutableStateOf(0) }
    AlertDialog(
        onDismissRequest = { ClientState.setToRate(null) },
        title = { Text(stringResource(R.string.rate_title)) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                order.priceFinal?.let { Text(stringResource(R.string.price, formatMoney(it)), fontWeight = FontWeight.Bold) }
                Row {
                    (1..5).forEach { star ->
                        IconButton(onClick = { rating = star }) {
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
            TextButton(onClick = { scope.launch { repository.rate(order.id, rating) } }, enabled = rating > 0) { Text(stringResource(R.string.save)) }
        },
        dismissButton = { TextButton(onClick = { ClientState.setToRate(null) }) { Text(stringResource(R.string.later)) } },
    )
}

@Composable
fun HistoryScreen(onBack: () -> Unit) {
    val repository = ClientApp.instance.repository
    var orders by remember { mutableStateOf<List<ClientOrder>?>(null) }
    BackHandler(onBack = onBack)
    LaunchedEffect(Unit) { orders = (repository.history(null) as? Result.Ok)?.value ?: emptyList() }

    Column(Modifier.fillMaxSize().statusBarsPadding()) {
        TopRow(stringResource(R.string.history), onBack)
        val list = orders
        if (list != null && list.isEmpty()) {
            Text(stringResource(R.string.history_empty), Modifier.padding(16.dp), color = LocalAppColors.current.muted)
        }
        LazyColumn {
            items(list.orEmpty(), key = { it.id }) { order ->
                Column(Modifier.fillMaxWidth().padding(16.dp), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text(order.createdAt.take(16).replace('T', ' '), Modifier.weight(1f), color = LocalAppColors.current.muted)
                        (order.priceFinal ?: order.priceEstimate)?.let { Text(stringResource(R.string.price, formatMoney(it)), fontWeight = FontWeight.Bold) }
                    }
                    Text(order.pickup.text.orEmpty())
                    order.dropoff?.text?.let { Text("→ $it") }
                    Text(statusText(order.status), style = MaterialTheme.typography.bodySmall, color = LocalAppColors.current.muted)
                }
                HorizontalDivider()
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
        }
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

@Composable
private fun TopRow(title: String, onBack: () -> Unit) {
    Row(Modifier.padding(8.dp), verticalAlignment = Alignment.CenterVertically) {
        IconButton(onClick = onBack) { Icon(Icons.AutoMirrored.Filled.ArrowBack, stringResource(R.string.back)) }
        Text(title, style = MaterialTheme.typography.titleLarge)
    }
}
