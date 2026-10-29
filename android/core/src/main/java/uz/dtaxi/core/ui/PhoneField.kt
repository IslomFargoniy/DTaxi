package uz.dtaxi.core.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.interaction.collectIsFocusedAsState
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Check
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.KeyboardArrowDown
import androidx.compose.material.icons.filled.Search
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Text
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.platform.LocalConfiguration
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import uz.dtaxi.core.R
import uz.dtaxi.core.data.Phones

/**
 * Telefon raqami (qaror #74): boshida davlat (bayroq + kod, bosilsa ro'yxat), keyin milliy qism shu davlat
 * formatida. Holatda faqat raqamlar; to'liq raqam joylansa davlat o'zi almashadi.
 */
@Composable
fun PhoneField(
    country: String,
    national: String,
    onChange: (country: String, national: String) -> Unit,
    modifier: Modifier = Modifier,
    imeAction: ImeAction = ImeAction.Next,
    onDone: () -> Unit = {},
    autoFocus: Boolean = false,
) {
    val context = LocalContext.current
    val interaction = remember { MutableInteractionSource() }
    val focused by interaction.collectIsFocusedAsState()
    var picking by remember { mutableStateOf(false) }
    val focus = remember { FocusRequester() }
    val colors = LocalAppColors.current
    val onSurface = MaterialTheme.colorScheme.onSurface
    val textStyle = TextStyle(fontSize = 18.sp, fontWeight = FontWeight.Medium, color = onSurface, letterSpacing = 0.3.sp)
    val transformation = remember(country) { PhoneVisualTransformation(country) }
    val placeholder = remember(country) { Phones.example(country) }
    val countryLabel = stringResource(R.string.phone_country, Phones.name(country, LocalConfiguration.current.locales[0]), Phones.dial(country))

    Row(
        modifier
            .fillMaxWidth()
            .height(60.dp)
            .clip(RoundedCornerShape(16.dp))
            .background(colors.tile)
            .border(2.dp, if (focused) onSurface else Color.Transparent, RoundedCornerShape(16.dp)),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Row(
            Modifier
                .fillMaxHeight()
                .clickable { picking = true }
                .semantics { contentDescription = countryLabel }
                .padding(start = 14.dp, end = 10.dp),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            Text(Phones.flag(country), fontSize = 20.sp)
            Text("+${Phones.dial(country)}", style = textStyle.copy(fontWeight = FontWeight.SemiBold))
            Icon(Icons.Default.KeyboardArrowDown, contentDescription = null, tint = colors.muted, modifier = Modifier.size(20.dp))
        }
        Box(Modifier.width(1.dp).height(28.dp).background(MaterialTheme.colorScheme.outlineVariant))
        Box(Modifier.weight(1f).padding(horizontal = 14.dp), contentAlignment = Alignment.CenterStart) {
            if (national.isEmpty()) Text(placeholder, style = textStyle.copy(color = colors.muted))
            BasicTextField(
                value = national,
                onValueChange = { raw ->
                    val (iso, digits) = Phones.input(country, national, raw)
                    onChange(iso, digits)
                },
                singleLine = true,
                textStyle = textStyle,
                cursorBrush = SolidColor(onSurface),
                visualTransformation = transformation,
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone, autoCorrectEnabled = false, imeAction = imeAction),
                keyboardActions = KeyboardActions(onDone = { onDone() }, onGo = { onDone() }),
                interactionSource = interaction,
                modifier = Modifier.fillMaxWidth().focusRequester(focus),
            )
        }
    }

    if (autoFocus) {
        androidx.compose.runtime.LaunchedEffect(Unit) { runCatching { focus.requestFocus() } }
    }

    if (picking) {
        CountryPickerSheet(
            selected = country,
            onDismiss = { picking = false },
            onSelect = { iso ->
                picking = false
                Phones.remember(context, iso)
                // Raqam yangi davlatga sig'masa — ortig'i tashlanadi
                var digits = national
                while (Phones.tooLong(iso, digits)) digits = digits.dropLast(1)
                onChange(iso, digits)
                runCatching { focus.requestFocus() }
            },
        )
    }
}

/** Davlat tanlash: qidiruv (nom, kod yoki "+998"), tepada qo'shni davlatlar */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun CountryPickerSheet(selected: String, onDismiss: () -> Unit, onSelect: (String) -> Unit) {
    val locale = LocalConfiguration.current.locales[0]
    val colors = LocalAppColors.current
    var query by remember { mutableStateOf("") }
    val all = remember(locale) {
        Phones.countries.map { it to Phones.name(it.iso, locale) }.sortedBy { it.second.lowercase(locale) }
    }
    val q = query.trim().removePrefix("+").lowercase(locale)
    val found = if (q.isEmpty()) emptyList() else all.filter { (c, name) ->
        name.lowercase(locale).contains(q) || c.dial.toString().startsWith(q) || c.iso.equals(q, ignoreCase = true)
    }
    val nearby = remember(all) { Phones.nearby.mapNotNull { iso -> all.firstOrNull { it.first.iso == iso } } }

    ModalBottomSheet(
        onDismissRequest = onDismiss,
        sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true),
        containerColor = MaterialTheme.colorScheme.surface,
    ) {
        Column(Modifier.fillMaxWidth()) {
            Row(
                Modifier.fillMaxWidth().padding(start = 20.dp, end = 8.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text(
                    stringResource(R.string.choose_country),
                    style = MaterialTheme.typography.titleLarge,
                    fontWeight = FontWeight.Bold,
                    modifier = Modifier.weight(1f),
                )
                IconButton(onClick = onDismiss) {
                    Icon(Icons.Default.Close, contentDescription = stringResource(R.string.close_sheet))
                }
            }
            Row(
                Modifier
                    .padding(horizontal = 20.dp, vertical = 8.dp)
                    .fillMaxWidth()
                    .height(48.dp)
                    .clip(RoundedCornerShape(14.dp))
                    .background(colors.tile)
                    .padding(horizontal = 14.dp),
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.spacedBy(10.dp),
            ) {
                Icon(Icons.Default.Search, contentDescription = null, tint = colors.muted)
                Box(Modifier.weight(1f)) {
                    val style = TextStyle(fontSize = 16.sp, color = MaterialTheme.colorScheme.onSurface)
                    if (query.isEmpty()) Text(stringResource(R.string.search_country), style = style.copy(color = colors.muted))
                    BasicTextField(
                        value = query,
                        onValueChange = { query = it.take(40) },
                        singleLine = true,
                        textStyle = style,
                        cursorBrush = SolidColor(MaterialTheme.colorScheme.onSurface),
                        modifier = Modifier.fillMaxWidth(),
                    )
                }
            }
            LazyColumn(Modifier.fillMaxWidth().heightIn(max = 560.dp)) {
                if (q.isEmpty()) {
                    item { SectionLabel(stringResource(R.string.nearby_countries)) }
                    items(nearby, key = { "n-" + it.first.iso }) { (c, name) -> CountryRow(c, name, c.iso == selected) { onSelect(c.iso) } }
                    item {
                        HorizontalDivider(Modifier.padding(horizontal = 20.dp, vertical = 8.dp))
                        SectionLabel(stringResource(R.string.all_countries))
                    }
                    items(all, key = { it.first.iso }) { (c, name) -> CountryRow(c, name, c.iso == selected) { onSelect(c.iso) } }
                } else {
                    items(found, key = { it.first.iso }) { (c, name) -> CountryRow(c, name, c.iso == selected) { onSelect(c.iso) } }
                    if (found.isEmpty()) {
                        item {
                            Text(
                                stringResource(R.string.nothing_found_country),
                                color = colors.muted,
                                modifier = Modifier.fillMaxWidth().padding(24.dp),
                            )
                        }
                    }
                }
                item { Spacer(Modifier.height(24.dp)) }
            }
        }
    }
}

@Composable
private fun SectionLabel(text: String) {
    Text(
        text.uppercase(),
        style = MaterialTheme.typography.labelMedium,
        fontWeight = FontWeight.Bold,
        letterSpacing = 0.6.sp,
        color = LocalAppColors.current.muted,
        modifier = Modifier.padding(horizontal = 20.dp, vertical = 6.dp),
    )
}

@Composable
private fun CountryRow(country: Phones.Country, name: String, selected: Boolean, onClick: () -> Unit) {
    Row(
        Modifier
            .fillMaxWidth()
            .height(52.dp)
            .background(if (selected) MaterialTheme.colorScheme.primaryContainer.copy(alpha = 0.5f) else Color.Transparent)
            .clickable(onClick = onClick)
            .padding(horizontal = 20.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(14.dp),
    ) {
        Text(country.flag, fontSize = 22.sp)
        Text(
            name,
            fontWeight = if (selected) FontWeight.SemiBold else FontWeight.Normal,
            maxLines = 1,
            modifier = Modifier.weight(1f),
        )
        Text("+${country.dial}", color = LocalAppColors.current.muted)
        if (selected) Icon(Icons.Default.Check, contentDescription = null, modifier = Modifier.size(20.dp))
    }
}
