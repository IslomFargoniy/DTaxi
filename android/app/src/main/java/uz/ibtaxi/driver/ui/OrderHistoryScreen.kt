package uz.ibtaxi.driver.ui

import android.os.Bundle
import androidx.activity.compose.BackHandler
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.derivedStateOf
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLifecycleOwner
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import org.maplibre.android.camera.CameraPosition
import org.maplibre.android.camera.CameraUpdateFactory
import org.maplibre.android.geometry.LatLng
import org.maplibre.android.geometry.LatLngBounds
import org.maplibre.android.maps.MapLibreMap
import org.maplibre.android.maps.MapView
import org.maplibre.android.maps.Style
import org.maplibre.android.style.expressions.Expression
import org.maplibre.android.style.layers.CircleLayer
import org.maplibre.android.style.layers.LineLayer
import org.maplibre.android.style.layers.Property
import org.maplibre.android.style.layers.PropertyFactory
import org.maplibre.android.style.sources.GeoJsonSource
import org.maplibre.geojson.Feature
import org.maplibre.geojson.FeatureCollection
import org.maplibre.geojson.LineString
import org.maplibre.geojson.Point
import uz.ibtaxi.driver.IbTaxiApp
import uz.ibtaxi.driver.R
import uz.ibtaxi.driver.data.DriverState
import uz.ibtaxi.driver.data.GeoPoint
import uz.ibtaxi.driver.data.HistoryOrder
import uz.ibtaxi.driver.data.OrderTrack
import uz.ibtaxi.driver.data.Result
import java.util.Locale

/** Ikkala rejimda ham ko'rinadigan belgilar: yo'l — firma sarig'i qora hoshiya bilan */
private const val TRACK_YELLOW = "#FFC800"
private const val START_GREEN = "#16A34A"
private const val FINISH_RED = "#E5484D"
private val StartGreen = Color(0xFF16A34A)
private val FinishRed = Color(0xFFE5484D)

/**
 * Buyurtmalar tarixi: qayerda olindi va qayerda yakunlandi (sahifalab, pastga surganda keyingisi yuklanadi).
 * Bosilganda — xaritada haqiqiy yurilgan yo'l.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun OrderHistoryScreen(onBack: () -> Unit) {
    val repository = IbTaxiApp.instance.repository
    val items = remember { mutableStateListOf<HistoryOrder>() }
    var nextPage by remember { mutableStateOf<Int?>(1) }
    var loading by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var selected by remember { mutableStateOf<HistoryOrder?>(null) }
    var attempt by remember { mutableStateOf(0) }
    val listState = rememberLazyListState()
    val nearEnd by remember {
        derivedStateOf {
            val last = listState.layoutInfo.visibleItemsInfo.lastOrNull()?.index ?: -1
            last >= listState.layoutInfo.totalItemsCount - 4
        }
    }

    LaunchedEffect(nearEnd, nextPage, attempt, selected) {
        val page = nextPage ?: return@LaunchedEffect
        // Safar ochiq paytda ro'yxat chizilmaydi (nearEnd yolg'on "true") — keyingi sahifa yuklanmasin
        if (!nearEnd || loading || error != null || selected != null) return@LaunchedEffect
        loading = true
        when (val result = repository.orderHistory(page)) {
            is Result.Ok -> {
                items.addAll(result.value.items.filter { new -> items.none { it.id == new.id } })
                nextPage = result.value.nextPage
            }
            is Result.Error -> error = result.message ?: IbTaxiApp.instance.getString(R.string.network_error)
        }
        loading = false
    }

    selected?.let { order ->
        BackHandler { selected = null }
        TripScreen(order) { selected = null }
        return
    }

    Scaffold(topBar = {
        TopAppBar(title = { Text(stringResource(R.string.order_history)) }, navigationIcon = {
            IconButton(onClick = onBack) { Icon(Icons.AutoMirrored.Filled.ArrowBack, stringResource(R.string.back)) }
        })
    }) { padding ->
        LazyColumn(
            Modifier.padding(padding).fillMaxSize(),
            state = listState,
            contentPadding = androidx.compose.foundation.layout.PaddingValues(16.dp),
            verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            items(items, key = { it.id }) { order -> HistoryCard(order) { selected = order } }
            item {
                Box(Modifier.fillMaxWidth().padding(vertical = 12.dp), contentAlignment = Alignment.Center) {
                    when {
                        loading -> CircularProgressIndicator(Modifier.size(28.dp))
                        error != null -> Column(horizontalAlignment = Alignment.CenterHorizontally) {
                            Text(error ?: "", color = MaterialTheme.colorScheme.error)
                            TextButton(onClick = {
                                error = null
                                attempt++
                            }) { Text(stringResource(R.string.retry)) }
                        }
                        nextPage == null && items.isEmpty() -> Text(stringResource(R.string.history_empty), color = LocalAppColors.current.muted)
                    }
                }
            }
        }
    }
}

@Composable
private fun HistoryCard(order: HistoryOrder, onClick: () -> Unit) {
    val appColors = LocalAppColors.current
    Surface(
        onClick = onClick,
        shape = RoundedCornerShape(18.dp),
        color = MaterialTheme.colorScheme.surface,
        border = androidx.compose.foundation.BorderStroke(1.dp, MaterialTheme.colorScheme.outlineVariant),
        modifier = Modifier.fillMaxWidth(),
    ) {
        Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(
                    localTime(order.createdAt, "dd.MM.yyyy HH:mm") ?: "",
                    Modifier.weight(1f),
                    style = MaterialTheme.typography.bodyMedium,
                    color = appColors.muted,
                )
                StatusChip(order.status)
            }
            PlaceRow(StartGreen, stringResource(R.string.picked_up), startPlace(order), localTime(order.startedAt))
            PlaceRow(FinishRed, stringResource(R.string.dropped_off), finishPlace(order), localTime(order.completedAt ?: order.cancelledAt))
            if (order.status == "completed") {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(
                        listOfNotNull(order.distanceM?.let(::km), order.durationS?.let { minutes(it) }).joinToString(" · "),
                        Modifier.weight(1f),
                        color = appColors.muted,
                    )
                    if (order.cashless) {
                        Text(stringResource(R.string.corporate_short), Modifier.padding(end = 8.dp), style = MaterialTheme.typography.labelMedium, color = appColors.muted)
                    }
                    Text("${formatMoney(order.price)} ${stringResource(R.string.som)}", fontWeight = FontWeight.ExtraBold, style = MaterialTheme.typography.titleMedium)
                }
            }
        }
    }
}

@Composable
private fun StatusChip(status: String) {
    val (container, content) = if (status == "completed") {
        MaterialTheme.colorScheme.tertiaryContainer to MaterialTheme.colorScheme.onTertiaryContainer
    } else {
        MaterialTheme.colorScheme.errorContainer to MaterialTheme.colorScheme.onErrorContainer
    }
    Text(
        statusLabel(status),
        Modifier.background(container, RoundedCornerShape(10.dp)).padding(horizontal = 10.dp, vertical = 4.dp),
        style = MaterialTheme.typography.labelMedium,
        fontWeight = FontWeight.Bold,
        color = content,
    )
}

@Composable
private fun statusLabel(status: String): String = when (status) {
    "completed" -> stringResource(R.string.status_completed)
    "cancelled_by_client" -> stringResource(R.string.status_cancelled_by_client)
    "cancelled_by_operator" -> stringResource(R.string.status_cancelled_by_operator)
    else -> stringResource(R.string.status_cancelled_by_driver)
}

@Composable
private fun PlaceRow(dot: Color, label: String, place: String, time: String?) {
    Row(verticalAlignment = Alignment.Top) {
        Box(Modifier.padding(top = 5.dp).size(12.dp).background(dot, CircleShape))
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f)) {
            Text(label, style = MaterialTheme.typography.labelMedium, color = LocalAppColors.current.muted)
            Text(place, style = MaterialTheme.typography.bodyLarge, fontWeight = FontWeight.SemiBold, maxLines = 2, overflow = TextOverflow.Ellipsis)
        }
        time?.let { Text(it, Modifier.padding(start = 8.dp), style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.Bold) }
    }
}

/** Haqiqiy joy nomi bo'lmasa — buyurtmadagi manzil */
private fun startPlace(order: HistoryOrder): String = order.startName ?: order.pickupText ?: "—"

private fun finishPlace(order: HistoryOrder): String = order.finishName ?: order.dropoffText ?: "—"

private fun km(meters: Int): String = String.format(Locale.ROOT, "%.1f", meters / 1000.0) + " " + IbTaxiApp.instance.getString(R.string.km)

private fun minutes(seconds: Int): String = "${(seconds + 59) / 60} " + IbTaxiApp.instance.getString(R.string.minutes_short)

/**
 * Bitta safar: xaritada yurilgan yo'l (GPS treki), yashil — olingan joy, qizil — yakunlangan joy; pastda tafsilotlar.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun TripScreen(order: HistoryOrder, onBack: () -> Unit) {
    val repository = IbTaxiApp.instance.repository
    val profile by DriverState.profile.collectAsState()
    var track by remember(order.id) { mutableStateOf<OrderTrack?>(null) }
    LaunchedEffect(order.id) {
        // Voz kechilgan buyurtmada yo'l yo'q — so'rov ham shart emas
        if (order.status != "cancelled_by_driver" || order.startedAt != null) {
            (repository.orderTrack(order.id) as? Result.Ok)?.value?.let { track = it }
        }
    }
    val start = track?.start ?: order.start ?: order.pickup
    val finish = track?.finish ?: order.finish ?: order.dropoff
    val line = track?.points?.takeIf { it.size >= 2 }?.map { GeoPoint(it[0], it[1]) }
    val appColors = LocalAppColors.current

    Scaffold(topBar = {
        TopAppBar(title = { Text("${stringResource(R.string.trip)} #${order.id}") }, navigationIcon = {
            IconButton(onClick = onBack) { Icon(Icons.AutoMirrored.Filled.ArrowBack, stringResource(R.string.back)) }
        })
    }) { padding ->
        Column(Modifier.padding(padding).fillMaxSize()) {
            profile?.let { p ->
                TripMap(
                    styleUrl = mapStyleFor(p.config.mapStyle, appColors.dark),
                    start = start,
                    finish = finish,
                    track = line,
                    modifier = Modifier.fillMaxWidth().weight(1f),
                )
            }
            Column(
                Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(16.dp),
                verticalArrangement = Arrangement.spacedBy(10.dp),
            ) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(localTime(order.createdAt, "dd.MM.yyyy HH:mm") ?: "", Modifier.weight(1f), color = appColors.muted)
                    StatusChip(order.status)
                }
                PlaceRow(StartGreen, stringResource(R.string.picked_up), startPlace(order), localTime(order.startedAt))
                PlaceRow(FinishRed, stringResource(R.string.dropped_off), finishPlace(order), localTime(order.completedAt ?: order.cancelledAt))
                if (track != null && line == null && order.startedAt != null) {
                    Text(stringResource(R.string.track_missing), style = MaterialTheme.typography.bodySmall, color = appColors.muted)
                }
                if (order.status == "completed") {
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        TripStat(order.distanceM?.let(::km) ?: "—", stringResource(R.string.distance), Modifier.weight(1f))
                        TripStat(order.durationS?.let { minutes(it) } ?: "—", stringResource(R.string.trip_duration), Modifier.weight(1f))
                        TripStat(formatMoney(order.price), stringResource(if (order.cashless) R.string.corporate_short else R.string.price), Modifier.weight(1f))
                    }
                    order.commission?.takeIf { it > 0 }?.let {
                        Text("${stringResource(R.string.tx_commission)}: ${formatMoney(it)} ${stringResource(R.string.som)}", color = appColors.muted)
                    }
                }
            }
        }
    }
}

@Composable
private fun TripStat(value: String, label: String, modifier: Modifier = Modifier) {
    val appColors = LocalAppColors.current
    Column(modifier.background(appColors.tile, RoundedCornerShape(16.dp)).padding(12.dp)) {
        Text(value, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.ExtraBold, maxLines = 1)
        Text(label, style = MaterialTheme.typography.bodySmall, color = appColors.muted, maxLines = 1)
    }
}

/**
 * Safar xaritasi: yo'l chizig'i (trek bo'lmasa — boshlanish va tugash orasida to'g'ri chiziq) va ikki nuqta,
 * kamera ikkalasini ham sig'diradi. Navigatordagi TaxiMap'dan alohida — kuzatish va strelka kerak emas.
 */
@Composable
private fun TripMap(styleUrl: String, start: GeoPoint?, finish: GeoPoint?, track: List<GeoPoint>?, modifier: Modifier = Modifier) {
    val context = LocalContext.current
    val mapView = remember { MapView(context).apply { onCreate(Bundle()) } }
    val holder = remember { arrayOfNulls<MapLibreMap>(1) }
    var styleReady by remember { mutableStateOf(false) }
    val lifecycle = LocalLifecycleOwner.current.lifecycle

    DisposableEffect(lifecycle) {
        // Observer qo'shilganda joriy holatgacha (START, RESUME) hodisalarni o'zi yuboradi
        var started = false
        var resumed = false
        val observer = LifecycleEventObserver { _, event ->
            when (event) {
                Lifecycle.Event.ON_START -> mapView.onStart().also { started = true }
                Lifecycle.Event.ON_RESUME -> mapView.onResume().also { resumed = true }
                Lifecycle.Event.ON_PAUSE -> mapView.onPause().also { resumed = false }
                Lifecycle.Event.ON_STOP -> mapView.onStop().also { started = false }
                else -> Unit
            }
        }
        lifecycle.addObserver(observer)
        // Ekran yopilganda (ilova emas) xarita ham yopiladi — ro'yxatga qaytganda xotira bo'shaydi
        onDispose {
            lifecycle.removeObserver(observer)
            if (resumed) mapView.onPause()
            if (started) mapView.onStop()
            mapView.onDestroy()
        }
    }

    AndroidView(factory = {
        mapView.also { view ->
            view.getMapAsync { map ->
                holder[0] = map
                map.uiSettings.isRotateGesturesEnabled = false
                map.uiSettings.isCompassEnabled = false
                map.cameraPosition = CameraPosition.Builder().target(UZBEKISTAN_CENTER).zoom(UZBEKISTAN_ZOOM).build()
                map.setStyle(Style.Builder().fromUri(styleUrl)) { style ->
                    addTripLayers(style)
                    styleReady = true
                }
            }
        }
    }, modifier = modifier)

    LaunchedEffect(styleReady, start, finish, track) {
        val map = holder[0] ?: return@LaunchedEffect
        val style = map.style ?: return@LaunchedEffect
        if (!styleReady) return@LaunchedEffect
        val line = track ?: listOfNotNull(start, finish).takeIf { it.size == 2 }
        style.getSourceAs<GeoJsonSource>("trip-line")?.setGeoJson(
            FeatureCollection.fromFeatures(
                line?.let { listOf(Feature.fromGeometry(LineString.fromLngLats(it.map { p -> Point.fromLngLat(p.lng, p.lat) }))) } ?: emptyList(),
            ),
        )
        style.getSourceAs<GeoJsonSource>("trip-points")?.setGeoJson(
            FeatureCollection.fromFeatures(
                listOfNotNull(start?.let { tripPoint(it, "start") }, finish?.let { tripPoint(it, "finish") }),
            ),
        )
        val all = (line ?: emptyList()) + listOfNotNull(start, finish)
        when {
            all.size >= 2 && all.distinct().size >= 2 -> {
                val bounds = LatLngBounds.Builder().apply { all.forEach { include(LatLng(it.lat, it.lng)) } }.build()
                map.easeCamera(CameraUpdateFactory.newLatLngBounds(bounds, 90))
            }
            all.isNotEmpty() -> map.easeCamera(CameraUpdateFactory.newLatLngZoom(LatLng(all[0].lat, all[0].lng), 15.0))
        }
    }
}

private fun addTripLayers(style: Style) {
    style.addSource(GeoJsonSource("trip-line"))
    style.addSource(GeoJsonSource("trip-points"))
    // Sariq chiziq qora hoshiya bilan — yorug' va qorong'i xaritada ham aniq ko'rinadi
    style.addLayer(
        LineLayer("trip-casing", "trip-line").withProperties(
            PropertyFactory.lineColor("#111111"),
            PropertyFactory.lineWidth(9f),
            PropertyFactory.lineCap(Property.LINE_CAP_ROUND),
            PropertyFactory.lineJoin(Property.LINE_JOIN_ROUND),
        ),
    )
    style.addLayer(
        LineLayer("trip-line", "trip-line").withProperties(
            PropertyFactory.lineColor(TRACK_YELLOW),
            PropertyFactory.lineWidth(5.5f),
            PropertyFactory.lineCap(Property.LINE_CAP_ROUND),
            PropertyFactory.lineJoin(Property.LINE_JOIN_ROUND),
        ),
    )
    style.addLayer(
        CircleLayer("trip-points", "trip-points").withProperties(
            PropertyFactory.circleRadius(9f),
            PropertyFactory.circleStrokeWidth(3f),
            PropertyFactory.circleStrokeColor("#ffffff"),
            PropertyFactory.circleColor(
                Expression.match(
                    Expression.get("kind"),
                    Expression.literal(FINISH_RED),
                    Expression.stop("start", START_GREEN),
                ),
            ),
        ),
    )
}

private fun tripPoint(point: GeoPoint, kind: String) =
    Feature.fromGeometry(Point.fromLngLat(point.lng, point.lat)).apply { addStringProperty("kind", kind) }
