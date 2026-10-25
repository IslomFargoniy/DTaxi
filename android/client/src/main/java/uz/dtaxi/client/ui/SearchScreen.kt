package uz.dtaxi.client.ui

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.BookmarkBorder
import androidx.compose.material.icons.filled.Place
import androidx.compose.material.icons.filled.Star
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.AssistChip
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
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
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import uz.dtaxi.client.ClientApp
import uz.dtaxi.client.R
import uz.dtaxi.client.data.LatLng
import uz.dtaxi.client.data.Place
import uz.dtaxi.client.data.Result
import uz.dtaxi.client.data.SavedAddress
import uz.dtaxi.core.ui.LocalAppColors

/**
 * Manzil qidiruvi (operator panelidagi bilan bir xil baza): bo'sh bo'lsa — saqlangan va ko'p ishlatilgan manzillar.
 */
@Composable
fun SearchScreen(target: SearchTarget, near: LatLng?, onPick: (Place) -> Unit, onBack: () -> Unit) {
    val repository = ClientApp.instance.repository
    val scope = rememberCoroutineScope()
    var query by remember { mutableStateOf("") }
    var results by remember { mutableStateOf<List<Place>>(emptyList()) }
    var saved by remember { mutableStateOf<List<SavedAddress>>(emptyList()) }
    var searched by remember { mutableStateOf(false) }
    var saving by remember { mutableStateOf<Place?>(null) }
    val focus = remember { FocusRequester() }

    BackHandler(onBack = onBack)
    LaunchedEffect(Unit) {
        focus.requestFocus()
        (repository.addresses() as? Result.Ok)?.let { saved = it.value }
    }
    // 2 harfdan keyin, terish to'xtagach (400 ms) — har harfga so'rov ketmasin
    LaunchedEffect(query) {
        if (query.trim().length < 2) {
            results = emptyList()
            searched = false
            return@LaunchedEffect
        }
        delay(400)
        (repository.places(query.trim(), near) as? Result.Ok)?.let {
            results = it.value
            searched = true
        }
    }

    Column(Modifier.fillMaxSize().statusBarsPadding().imePadding()) {
        Row(Modifier.padding(8.dp), verticalAlignment = Alignment.CenterVertically) {
            IconButton(onClick = onBack) { Icon(Icons.AutoMirrored.Filled.ArrowBack, stringResource(R.string.back)) }
            OutlinedTextField(
                value = query,
                onValueChange = { query = it },
                label = { Text(stringResource(if (target == SearchTarget.Pickup) R.string.from else R.string.to)) },
                placeholder = { Text(stringResource(R.string.search_hint)) },
                singleLine = true,
                modifier = Modifier.weight(1f).focusRequester(focus),
            )
        }
        LazyColumn(Modifier.fillMaxSize()) {
            if (query.trim().length < 2 && saved.isNotEmpty()) {
                item { SectionLabel(stringResource(R.string.saved_addresses)) }
                items(saved, key = { "s${it.id}" }) { address ->
                    PlaceRow(
                        title = address.label ?: address.text,
                        subtitle = address.label?.let { address.text },
                        starred = address.label != null,
                        onClick = { onPick(Place(address.text, address.lat, address.lng)) },
                    )
                }
            }
            items(results, key = { "r${it.landmarkId}-${it.lat}-${it.lng}" }) { place ->
                PlaceRow(
                    title = place.text.orEmpty(),
                    subtitle = null,
                    starred = false,
                    onClick = { onPick(place) },
                    onSave = { saving = place },
                )
            }
            if (searched && results.isEmpty()) {
                item { Text(stringResource(R.string.nothing_found), Modifier.padding(16.dp), color = LocalAppColors.current.muted) }
            }
        }
    }

    saving?.let { place ->
        SaveAddressDialog(
            onSave = { label ->
                saving = null
                scope.launch { (repository.saveAddress(label, place) as? Result.Ok)?.let { saved = listOf(it.value) + saved } }
            },
            onDismiss = { saving = null },
        )
    }
}

@Composable
private fun SectionLabel(text: String) {
    Text(text, Modifier.padding(start = 16.dp, top = 12.dp, bottom = 4.dp), style = MaterialTheme.typography.labelLarge, color = LocalAppColors.current.muted)
}

@Composable
private fun PlaceRow(title: String, subtitle: String?, starred: Boolean, onClick: () -> Unit, onSave: (() -> Unit)? = null) {
    Row(
        Modifier.fillMaxWidth().clickable(onClick = onClick).padding(horizontal = 16.dp, vertical = 12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(if (starred) Icons.Default.Star else Icons.Default.Place, null, tint = LocalAppColors.current.muted)
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(title, maxLines = 1, overflow = TextOverflow.Ellipsis)
            subtitle?.let { Text(it, style = MaterialTheme.typography.bodySmall, color = LocalAppColors.current.muted, maxLines = 1, overflow = TextOverflow.Ellipsis) }
        }
        onSave?.let { IconButton(onClick = it) { Icon(Icons.Default.BookmarkBorder, stringResource(R.string.save_address)) } }
    }
    HorizontalDivider()
}

@Composable
private fun SaveAddressDialog(onSave: (String) -> Unit, onDismiss: () -> Unit) {
    var label by remember { mutableStateOf("") }
    val home = stringResource(R.string.home)
    val work = stringResource(R.string.work)
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.save_address)) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    AssistChip(onClick = { label = home }, label = { Text(home) })
                    AssistChip(onClick = { label = work }, label = { Text(work) })
                }
                OutlinedTextField(
                    value = label,
                    onValueChange = { label = it.take(50) },
                    label = { Text(stringResource(R.string.address_label)) },
                    singleLine = true,
                )
            }
        },
        confirmButton = { TextButton(onClick = { onSave(label.trim()) }, enabled = label.isNotBlank()) { Text(stringResource(R.string.save)) } },
        dismissButton = { TextButton(onClick = onDismiss) { Text(stringResource(R.string.cancel)) } },
    )
}
