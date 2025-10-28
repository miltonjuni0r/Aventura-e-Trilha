document.addEventListener("DOMContentLoaded", () => {
  const botoesReserva = document.querySelectorAll(".botao-pacote");
  const painel = document.getElementById("painelReserva");
  const fechar = document.querySelector(".fechar");
  const nomePacote = document.getElementById("nomePacote");
  const precoPacote = document.getElementById("precoPacote");
  const confirmar = document.getElementById("confirmarReserva");

  // Abrir o painel de reserva
  botoesReserva.forEach(botao => {
    botao.addEventListener("click", () => {
      const pacote = botao.parentElement;
      const titulo = pacote.querySelector("h3").innerText;
      const preco = pacote.querySelector(".preco").innerText;

      nomePacote.innerText = `Pacote: ${titulo}`;
      precoPacote.innerText = `Preço: ${preco}`;
      painel.style.display = "flex";

      // Limpa campos e mensagem de confirmação ao abrir
      document.getElementById("nomeCliente").value = "";
      document.getElementById("telefone").value = "";
      document.getElementById("email").value = "";
      const mensagem = document.getElementById("mensagemConfirmacao");
      if (mensagem) mensagem.innerText = "";
    });
  });

  // Fechar o painel
  fechar.addEventListener("click", () => {
    painel.style.display = "none";
  });

  // Confirmar reserva
  confirmar.addEventListener("click", () => {
    const nome = document.getElementById("nomeCliente").value.trim();
    const tel = document.getElementById("telefone").value.trim();
    const email = document.getElementById("email").value.trim();

    if (!nome || !tel || !email) {
      alert("Por favor, preencha todos os campos antes de confirmar.");
      return;
    }

    // Adiciona a mensagem de confirmação dentro do próprio painel
    let mensagem = document.getElementById("mensagemConfirmacao");

    if (!mensagem) {
      mensagem = document.createElement("p");
      mensagem.id = "mensagemConfirmacao";
      mensagem.style.color = "green";
      mensagem.style.fontWeight = "bold";
      painel.querySelector(".painel-conteudo").appendChild(mensagem);
    }

    mensagem.innerText = `✅ Pedido confirmado! Enviaremos mais informações no seu contato. Obrigado por reservar conosco, ${nome}!`;
  });
});
